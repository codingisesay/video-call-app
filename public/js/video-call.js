/* global io */
(function () {
  // =========================
  // Socket.io (signaling)
  // =========================
  const socket = io("https://vcall.payvance.co.in", {
    path: "/socket.io",
    transports: ["websocket"],
  });

  // =========================
  // DOM refs
  // =========================
  const mixedCanvas = document.getElementById("mixedCanvas");
  const ctx = mixedCanvas.getContext("2d");
  const localVideo = document.getElementById("localVideo");
  const remoteVideo = document.getElementById("remoteVideo");
  const statusBadge = document.getElementById("statusBadge");
  const hangupBtn = document.getElementById("hangupBtn");

  // =========================
  // App / API context
  // - Laravel view should set window.Laravel.callToken + apiUrl
  // - jwt may arrive later (postMessage) or via URL (?token=)
  // =========================
  const API = (window.Laravel && window.Laravel.apiUrl) || "/api";
  const UPLOAD_ID = (window.Laravel && window.Laravel.callToken) || null;

  // read JWT from multiple places, preferring latest
  function initJWTFromUrlOrStorage() {
    try {
      const url = new URL(window.location.href);
      const t = url.searchParams.get("token");
      if (t && t.length > 20) {
        window.Laravel = window.Laravel || {};
        window.Laravel.jwtToken = t;
        try { localStorage.setItem("dao_jwt", t); } catch (_) {}
      } else if (!((window.Laravel && window.Laravel.jwtToken))) {
        const ls = localStorage.getItem("dao_jwt");
        const ss = sessionStorage.getItem("dao_jwt");
        if (ls && ls.length > 20) {
          window.Laravel = window.Laravel || {};
          window.Laravel.jwtToken = ls;
        } else if (ss && ss.length > 20) {
          window.Laravel = window.Laravel || {};
          window.Laravel.jwtToken = ss;
        }
      }
    } catch (_) {}
  }
  initJWTFromUrlOrStorage();

  function getJWT() {
    return (window.Laravel && window.Laravel.jwtToken) || null;
  }

  // =========================
  // Accept JWT via postMessage from DAO
  // =========================
  window.addEventListener("message", (evt) => {
    const allowed = [
      "https://dao.payvance.co.in",
      "https://dao.payvance.co.in:8091",
      "https://vcall.payvance.co.in",
      "https://localhost:5173",
      "http://localhost:5173",
    ];
    if (!allowed.includes(evt.origin)) return;

    const msg = evt.data;
    if (msg && msg.type === "DAO_JWT" && typeof msg.token === "string" && msg.token.length > 20) {
      window.Laravel = window.Laravel || {};
      window.Laravel.jwtToken = msg.token;
      try { localStorage.setItem("dao_jwt", msg.token); } catch (_) {}
      console.log("[vcall] JWT received via postMessage");

      // If already connected and not recording yet, start now
      if (peerConnection && callStarted && !recording && getJWT()) {
        startChunkedRecording();
      }
    }
  });

  // =========================
  // State
  // =========================
  let localStream = null;
  let remoteStream = null;
  let peerConnection = null;

  // attach-once controls for remote video
  let remoteAttached = false;
  let remotePlayTried = false;

  // Canvas loop
  let mixAnimationId = null;
  let callStarted = false;
  let callEnded = false;

  // Recording/chunks
  let recorder = null;
  let recording = false;
  let finalized = false;
  let seq = 0;

  // Grace timers (ICE)
  let dcTimer = null;
  let failedTimer = null;

  // PiP drag for local preview
  const pipPos = { x: 420, y: 300 };
  let dragging = false;
  let dragOffset = { x: 0, y: 0 };

  // =========================
  // UI helpers
  // =========================
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

  // Drag handlers
  localVideo.addEventListener("mousedown", (e) => {
    dragging = true;
    // convert page coords to container-relative; keep simple bounds
    const rect = localVideo.getBoundingClientRect();
    dragOffset.x = e.clientX - rect.left;
    dragOffset.y = e.clientY - rect.top;
  });
  window.addEventListener("mousemove", (e) => {
    if (!dragging) return;
    // canvas size 640x480, PiP size 160x120
    pipPos.x = Math.max(0, Math.min(640 - 160, e.clientX - dragOffset.x));
    pipPos.y = Math.max(0, Math.min(480 - 120, e.clientY - dragOffset.y));
    updatePipPosition();
  });
  window.addEventListener("mouseup", () => { dragging = false; });

  // Remote autoplay unlock (mobile/safari)
  document.body.addEventListener("click", () => {
    remoteVideo?.play().catch(() => {});
  });

  // End call button
  hangupBtn?.addEventListener("click", hangup);

  // =========================
  // Audio mix helpers
  // =========================
  let audioCtx = null;
  let mixDest = null;

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

  // Build MediaStream with canvas video + merged audio (local + remote)
  function buildMixedMediaStream(canvasStream, localStreamIn, remoteStreamIn) {
    ensureAudioContext();
    mixDest = audioCtx.createMediaStreamDestination(); // fresh destination

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

  // =========================
  // Boot
  // =========================
  (async function start() {
    setStatus("Initializing camera...");
    try {
      // Request cam+mic
      localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
      localVideo.srcObject = localStream;
      localVideo.muted = true;
      updatePipPosition();

      if (!UPLOAD_ID) {
        console.warn("No callToken/upload_id on page; WebRTC will work but uploads will fail.");
      }

      // Join signaling room
      socket.emit("joinRoom", UPLOAD_ID);
      setStatus("Waiting for peer...");
    } catch (err) {
      console.error(err);
      setStatus("Camera/Mic error: " + (err.message || err.name || err));
      alert("Camera/Mic error: " + (err.message || err.name || err));
    }
  })();

  // =========================
  // Signaling
  // =========================
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

  // =========================
  // WebRTC
  // =========================
  function clearTimers() {
    if (dcTimer) { clearTimeout(dcTimer); dcTimer = null; }
    if (failedTimer) { clearTimeout(failedTimer); failedTimer = null; }
  }

  function createPeerConnection() {
    peerConnection = new RTCPeerConnection({
      iceServers: [{ urls: "stun:stun.l.google.com:19302" }],
    });

    // Local tracks
    localStream.getTracks().forEach((t) => peerConnection.addTrack(t, localStream));

    // ICE candidates
    peerConnection.onicecandidate = (e) => {
      if (e.candidate) {
        socket.emit("ice-candidate", { roomId: UPLOAD_ID, candidate: e.candidate });
      }
    };

    // Remote media
    peerConnection.ontrack = (e) => {
      const stream = e.streams && e.streams[0] ? e.streams[0] : null;
      if (!stream) return;

      if (!remoteStream) {
        remoteStream = stream;
      }

      // Attach only once to avoid AbortError from repeated loads
      if (!remoteAttached) {
        remoteVideo.srcObject = remoteStream;
        remoteVideo.muted = false;
        remoteVideo.volume = 1.0;

        const tryPlay = () => {
          if (remotePlayTried) return;
          remotePlayTried = true;
          remoteVideo.play().catch(() => {
            const resume = () => {
              remoteVideo.play().catch(() => {});
              document.removeEventListener("click", resume);
              document.removeEventListener("touchstart", resume);
            };
            document.addEventListener("click", resume, { once: true });
            document.addEventListener("touchstart", resume, { once: true });
          });
        };

        if (remoteVideo.readyState >= 2) {
          tryPlay();
        } else {
          remoteVideo.addEventListener("canplay", tryPlay, { once: true });
        }

        remoteAttached = true;
      }
    };

    // ICE connection state (with grace)
    peerConnection.oniceconnectionstatechange = () => {
      const s = peerConnection.iceConnectionState;
      console.log("ICE state:", s);

      if (s === "connected" || s === "completed") {
        clearTimers();
        if (!callStarted) {
          callStarted = true;
          setStatus("Call connected");
          startPiPDrawing();
          if (getJWT() && !recording) startChunkedRecording();
          else if (!getJWT()) console.log("Viewer mode (no JWT) — not recording.");
        }
      }

      if (s === "disconnected") {
        clearTimers();
        // allow brief network blips to recover
        dcTimer = setTimeout(() => {
          if (peerConnection && peerConnection.iceConnectionState === "disconnected") {
            console.warn("ICE stayed disconnected; stopping recording.");
            stopRecording();
            setStatus("Call disconnected");
          }
        }, 15000);
      }

      if (s === "failed") {
        clearTimers();
        failedTimer = setTimeout(() => {
          if (peerConnection && peerConnection.iceConnectionState === "failed") {
            console.error("ICE failed persistently; stopping.");
            stopRecording();
            setStatus("Call failed");
          }
        }, 8000);
      }

      if (s === "closed") {
        clearTimers();
        stopRecording();
        setStatus("Call closed");
      }
    };

    // Generic connection state (informational)
    peerConnection.onconnectionstatechange = () => {
      console.log("Peer state:", peerConnection.connectionState);
    };
  }

  async function createAndSendOffer() {
    if (!peerConnection) createPeerConnection();
    const offer = await peerConnection.createOffer();
    await peerConnection.setLocalDescription(offer);
    socket.emit("offer", { roomId: UPLOAD_ID, offer });
  }

  // =========================
  // PiP drawing to canvas
  // =========================
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

  // =========================
  // Recording (chunked)
  // =========================
  function startChunkedRecording() {
    if (recording || !callStarted) return;
    if (!getJWT()) { console.warn("No JWT; skipping recording."); return; }

    setStatus("Recording...", true);
    recording = true;
    finalized = false;
    seq = 0;

    const canvasStream = mixedCanvas.captureStream(30);
    const mixed = buildMixedMediaStream(canvasStream, localStream, remoteStream);

    try {
      recorder = new MediaRecorder(mixed, { mimeType: "video/webm" });
    } catch (e) {
      console.warn("MediaRecorder with webm failed; trying default", e);
      recorder = new MediaRecorder(mixed);
    }

    recorder.ondataavailable = async (e) => {
      if (!e.data || !e.data.size) return;
      const fd = new FormData();
      fd.append("upload_id", UPLOAD_ID || "");
      fd.append("seq", String(seq));
      fd.append("chunk", e.data, `part_${seq}.webm`);
      seq++;

      try {
        const res = await fetch(API + "/upload-chunk", {
          method: "POST",
          headers: { Authorization: "Bearer " + getJWT() },
          body: fd,
        });
        if (!res.ok) {
          const txt = await res.text().catch(() => "");
          console.error("Chunk upload failed", res.status, txt);
        }
      } catch (err) {
        console.error("Chunk upload error", err);
      }
    };

    // 5s timeslice; first chunk ~5s, then continuous
    recorder.start(5000);
  }

  let finalizeOnceGuard = false;
  async function finalizeUpload() {
    if (finalizeOnceGuard) return;
    finalizeOnceGuard = true;
    if (finalized) return;
    finalized = true;

    if (!getJWT()) { console.log("No JWT — finalize skipped."); return; }
    if (!UPLOAD_ID) { console.warn("No upload_id/callToken — cannot finalize."); return; }

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
    // allow final ondataavailable to flush
    setTimeout(finalizeUpload, 300);
  }

  // =========================
  // Hang up / teardown
  // =========================
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

  // Try to finalize if tab closes
  window.addEventListener("beforeunload", finalizeUpload);
  window.addEventListener("pagehide", finalizeUpload);
})();
