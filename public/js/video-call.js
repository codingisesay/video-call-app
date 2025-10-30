// ==============================
// video-call.js (frontend)
// 5s chunking, mixed A/V capture, robust finalize
// ==============================
/* global io */
(function () {
  // ========= Socket.io (signaling) =========
  const socket = io("https://vcall.payvance.co.in", {
    path: "/socket.io",
    transports: ["websocket"],
  });

  // ========= DOM refs =========
  const mixedCanvas = document.getElementById("mixedCanvas");
  const ctx = mixedCanvas.getContext("2d");
  const localVideo = document.getElementById("localVideo");
  const remoteVideo = document.getElementById("remoteVideo");
  const statusBadge = document.getElementById("statusBadge");
  const hangupBtn = document.getElementById("hangupBtn");

  // ========= App / API context =========
  const API = (window.Laravel && window.Laravel.apiUrl) || "/api";
  const UPLOAD_ID = (window.Laravel && window.Laravel.callToken) || null; // meeting_token or self-kyc upload_id
  function getJWT() {
    return (window.Laravel && window.Laravel.jwtToken) || null; // agent token (may arrive later)
  }

  // ========= State =========
  let localStream = null;
  let remoteStream = null;
  let peerConnection = null;

  // Canvas mixing loop
  let mixAnimationId = null;
  let callStarted = false;
  let callEnded = false;

  // Recording/chunks
  let recorder = null;
  let recording = false;
  let finalized = false;
  let seq = 0; // chunk sequence counter
  let pendingUploads = 0; // in-flight chunk uploads

  // ========= Accept JWT via postMessage from DAO =========
  window.addEventListener('message', (evt) => {
    const allowed = [
      'https://dao.payvance.co.in',
      'https://dao.payvance.co.in:8091',
      'https://vcall.payvance.co.in',
      'https://localhost:5173',
      'http://localhost:5173',
    ];
    if (!allowed.includes(evt.origin)) return;

    const msg = evt.data;
    if (msg && msg.type === 'DAO_JWT' && typeof msg.token === 'string' && msg.token.length > 20) {
      window.Laravel = window.Laravel || {};
      window.Laravel.jwtToken = msg.token;
      console.log('[vcall] JWT received via postMessage');

      // If peer is already connected and we weren’t recording, start now
      if (peerConnection && peerConnection.connectionState === 'connected' && !recording) {
        startChunkedRecording();
      }
    }
  });

  // ========= PiP drag for local preview =========
  const pipPos = { x: 420, y: 300 };
  let dragging = false;
  let dragOffset = { x: 0, y: 0 };

  function setStatus(text, isRecording = false) {
    if (!statusBadge) return;
    statusBadge.textContent = text;
    statusBadge.classList.toggle("recording", !!isRecording);
  }
  function updatePipPosition() {
    if (!localVideo) return;
    localVideo.style.left = pipPos.x + "px";
    localVideo.style.top = pipPos.y + "px";
  }

  localVideo.addEventListener("mousedown", (e) => {
    dragging = true;
    dragOffset.x = e.clientX - pipPos.x;
    dragOffset.y = e.clientY - pipPos.y;
  });
  window.addEventListener("mousemove", (e) => {
    if (!dragging) return;
    pipPos.x = Math.max(0, Math.min(640 - 160, e.clientX - dragOffset.x));
    pipPos.y = Math.max(0, Math.min(480 - 120, e.clientY - dragOffset.y));
    updatePipPosition();
  });
  window.addEventListener("mouseup", () => { dragging = false; });

  // Remote autoplay unlock + resume AudioContext (mobile/safari policies)
  document.body.addEventListener("click", async () => {
    try { await remoteVideo?.play(); } catch {}
    if (audioCtx && audioCtx.state === "suspended") {
      try { await audioCtx.resume(); } catch {}
    }
  });

  // End call button
  hangupBtn?.addEventListener("click", hangup);

  // ========= Audio mix helpers (local + remote -> one track) =========
  let audioCtx = null;
  let mixDest = null; // MediaStreamDestination

  function ensureAudioContext() {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    if (!mixDest) mixDest = audioCtx.createMediaStreamDestination();
    return { audioCtx, mixDest };
  }

  function sourceFromTrack(track) {
    const { audioCtx } = ensureAudioContext();
    const ms = new MediaStream([track]);
    return audioCtx.createMediaStreamSource(ms);
  }

  function buildMixedMediaStream(canvasStream, localStreamIn, remoteStreamIn) {
    // Fresh destination each time to avoid stale graphs
    ensureAudioContext();
    mixDest = audioCtx.createMediaStreamDestination();

    const addAudioFrom = (stream) => {
      if (!stream) return;
      const t = stream.getAudioTracks()[0];
      if (!t) return;
      try {
        const src = sourceFromTrack(t);
        src.connect(mixDest);
      } catch (e) {
        console.warn("Audio connect failed:", e);
      }
    };

    addAudioFrom(localStreamIn);
    addAudioFrom(remoteStreamIn);

    const out = new MediaStream([
      ...canvasStream.getVideoTracks(),
      ...mixDest.stream.getAudioTracks(),
    ]);
    return out;
  }

  // ========= Boot =========
  (async function start() {
    setStatus("Initializing camera...");
    try {
      // Request cam+mic
      localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
      localVideo.srcObject = localStream;
      localVideo.muted = true; // prevent local echo
      updatePipPosition();

      if (!UPLOAD_ID) {
        console.warn("No callToken/upload_id on page; WebRTC will work but uploads will fail.");
      }
      socket.emit("joinRoom", UPLOAD_ID);
      setStatus("Waiting for peer...");
    } catch (err) {
      console.error(err);
      setStatus("Camera/Mic error: " + (err.message || err.name || err));
      alert("Camera/Mic error: " + (err.message || err.name || err));
    }
  })();

  // ========= Signaling =========
  socket.on("joinedRoom", (data) => {
    const isCaller = !!data.isCaller;
    if (isCaller) {
      createPeerConnection();
      createAndSendOffer();
    }
  });

  socket.on("peer-joined", () => {
    if (peerConnection) createAndSendOffer();
  });

  socket.on("offer", async (offer) => {
    if (!peerConnection) createPeerConnection();
    await peerConnection.setRemoteDescription(new RTCSessionDescription(offer));
    const answer = await peerConnection.createAnswer();
    await peerConnection.setLocalDescription(answer);
    socket.emit("answer", { roomId: UPLOAD_ID, answer });
  });

  socket.on("answer", async (answer) => {
    if (!peerConnection) return;
    await peerConnection.setRemoteDescription(new RTCSessionDescription(answer));
  });

  socket.on("ice-candidate", async (candidate) => {
    if (candidate && peerConnection) {
      try { await peerConnection.addIceCandidate(candidate); }
      catch (err) { console.error("ICE add error:", err); }
    }
  });

  // ========= WebRTC =========
  function createPeerConnection() {
    peerConnection = new RTCPeerConnection({
      iceServers: [{ urls: "stun:stun.l.google.com:19302" }],
    });

    // Local tracks
    localStream.getTracks().forEach((t) => peerConnection.addTrack(t, localStream));

    // ICE
    peerConnection.onicecandidate = (e) => {
      if (e.candidate) {
        socket.emit("ice-candidate", { roomId: UPLOAD_ID, candidate: e.candidate });
      }
    };

    // Remote stream
    peerConnection.ontrack = (e) => {
      if (!remoteStream) remoteStream = e.streams[0];
      remoteVideo.srcObject = remoteStream;
      remoteVideo.muted = false;
      remoteVideo.volume = 1.0;
      remoteVideo.play().catch((err) => console.warn("Remote play blocked:", err));

      // IMPORTANT: start recording here once remote track exists (JWT required)
      if (peerConnection.connectionState === "connected" && getJWT() && !recording) {
        startChunkedRecording();
      }
    };

    // Connection state
    peerConnection.onconnectionstatechange = () => {
      const st = peerConnection.connectionState;
      console.log("Peer state:", st);

      if (st === "connected") {
        if (!callStarted) {
          callStarted = true;
          setStatus("Call connected");

          // Start visual mixer
          startPiPDrawing();

          // If remote track already arrived earlier and JWT is present, ensure recording
          if (getJWT() && !recording && remoteStream) {
            startChunkedRecording();
          }
        }
      } else if (["failed", "disconnected", "closed"].includes(st)) {
        setStatus("Call disconnected");
        stopPiPDrawing();
        stopRecording();
      }
    };
  }

  async function createAndSendOffer() {
    if (!peerConnection) createPeerConnection();
    const offer = await peerConnection.createOffer();
    await peerConnection.setLocalDescription(offer);
    socket.emit("offer", { roomId: UPLOAD_ID, offer });
  }

  // ========= PiP drawing to canvas =========
  function startPiPDrawing() {
    mixedCanvas.width = 640;
    mixedCanvas.height = 480;

    function draw() {
      ctx.clearRect(0, 0, 640, 480);
      if (remoteVideo.readyState >= 2) {
        ctx.drawImage(remoteVideo, 0, 0, 640, 480);
      }
      if (localVideo.readyState >= 2) {
        ctx.drawImage(localVideo, pipPos.x, pipPos.y, 160, 120);
      }
      if (!callEnded) mixAnimationId = requestAnimationFrame(draw);
    }

    draw();
  }

  function stopPiPDrawing() {
    cancelAnimationFrame(mixAnimationId);
  }

  // ========= Recording (5s chunked) =========
  function startChunkedRecording() {
    if (recording || !callStarted) return;
    if (!getJWT()) { console.warn("No JWT; skipping recording."); return; }

    // Ensure we have both local and (ideally) remote audio before we proceed
    if (!remoteStream || remoteStream.getAudioTracks().length === 0) {
      console.log("Remote audio not ready; delaying recorder start by 300ms");
      setTimeout(() => {
        if (!recording && getJWT()) startChunkedRecording();
      }, 300);
      return;
    }

    setStatus("Recording...", true);
    recording = true;
    finalized = false;
    seq = 0;

    // 1) Canvas video stream
    const canvasStream = mixedCanvas.captureStream(30);

    // 2) Mixed stream (video + audio mix)
    const mixed = buildMixedMediaStream(canvasStream, localStream, remoteStream);

    // 3) Recorder
    try {
      recorder = new MediaRecorder(mixed, { mimeType: "video/webm;codecs=vp9,opus" });
    } catch (e) {
      try { recorder = new MediaRecorder(mixed, { mimeType: "video/webm" }); }
      catch (e2) { recorder = new MediaRecorder(mixed); }
    }

    // Upload helper with pending counter + tiny retry
    async function uploadChunk(fd) {
      pendingUploads++;
      try {
        for (let attempt = 1; attempt <= 3; attempt++) {
          const res = await fetch(API + "/upload-chunk", {
            method: "POST",
            headers: { Authorization: "Bearer " + getJWT() },
            body: fd,
          });
          if (res.ok) return;
          const txt = await res.text().catch(() => "");
          console.warn("Chunk upload failed", res.status, txt, "attempt", attempt);
          await new Promise(r => setTimeout(r, attempt * 300));
        }
      } catch (err) {
        console.error("Chunk upload error", err);
      } finally {
        pendingUploads--;
      }
    }

    recorder.ondataavailable = (e) => {
      if (!e.data || !e.data.size) return;
      const fd = new FormData();
      fd.append("upload_id", UPLOAD_ID || "");
      fd.append("seq", String(seq));
      fd.append("chunk", e.data, `part_${seq}.webm`);
      seq++;
      uploadChunk(fd);
    };

    recorder.onstop = async () => {
      // Wait for trailing uploads
      while (pendingUploads > 0) {
        await new Promise(r => setTimeout(r, 100));
      }
      await finalizeUpload();
    };

    // Fire every 5 seconds
    recorder.start(5000);
  }

  async function finalizeUpload() {
    if (finalized) return;
    finalized = true;

    if (!getJWT()) { console.log("No JWT — finalize skipped."); return; }
    if (!UPLOAD_ID) { console.warn("No upload_id/callToken on page — cannot finalize."); return; }

    try {
      const res = await fetch(API + "/finalize-upload", {
        method: "POST",
        headers: {
          Authorization: "Bearer " + getJWT(),
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ upload_id: UPLOAD_ID, total_parts: seq }),
      });
      const body = await res.text();
      if (!res.ok) throw new Error(`Finalize failed: ${res.status} ${body}`);
      console.log("Finalize ok:", body);
      setStatus("Upload complete!");
    } catch (err) {
      console.error("Finalize error:", err);
      setStatus("Upload error");
    }
  }

  

  function stopRecording() {
    if (recorder && recording) {
      setStatus("Uploading...");
      try { recorder.stop(); } catch (e) {}
      recording = false;
    }
  }

  // ========= Hang up =========
  function hangup() {
    setStatus("Call Ended");
    callEnded = true;
    callStarted = false;

    try { peerConnection?.close(); } catch (e) {}

    if (localStream) {
      localStream.getTracks().forEach((t) => t.stop());
      localVideo.srcObject = null;
    }
    if (remoteVideo?.srcObject) {
      remoteVideo.srcObject.getTracks?.().forEach((t) => t.stop());
      remoteVideo.srcObject = null;
    }

    stopPiPDrawing();
    stopRecording();
  }

  // Try to finalize if tab closes (graceful)
  window.addEventListener("beforeunload", () => { if (!finalized) stopRecording(); });
  window.addEventListener("pagehide", () => { if (!finalized) stopRecording(); });
})();

