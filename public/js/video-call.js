/* global io */
(function () {
  const socket = io("https://vcall.payvance.co.in", { path: "/socket.io", transports: ["websocket"] });

  let localStream, peerConnection, recorder;
  const mixedCanvas = document.getElementById("mixedCanvas");
  const ctx = mixedCanvas.getContext("2d");
  let mixAnimationId;

  let isCaller = false;
  let callStarted = false;
  let recording = false;
  let callEnded = false;
  let finalized = false;

  const pipPos = { x: 420, y: 300 };
  let dragging = false, dragOffset = { x: 0, y: 0 };

  const localVideo = document.getElementById("localVideo");
  const remoteVideo = document.getElementById("remoteVideo");
  const statusBadge = document.getElementById("statusBadge");
  const hangupBtn = document.getElementById("hangupBtn");

  // === Chunked upload state ===
  const API = (window.Laravel && window.Laravel.apiUrl) || "/api";
  const UPLOAD_ID = (window.Laravel && window.Laravel.callToken) || null; // meeting_token OR self-kyc upload_id
  let seq = 0;

  function setStatus(text, isRecording = false) {
    statusBadge.textContent = text;
    statusBadge.classList.toggle("recording", isRecording);
  }

  function updatePipPosition() {
    localVideo.style.left = pipPos.x + "px";
    localVideo.style.top  = pipPos.y + "px";
  }

  // Drag local PiP
  localVideo.addEventListener("mousedown", (e) => {
    dragging = true;
    dragOffset.x = e.clientX - pipPos.x;
    dragOffset.y = e.clientY - pipPos.y;
    localVideo.style.cursor = "grabbing";
  });
  window.addEventListener("mousemove", (e) => {
    if (!dragging) return;
    pipPos.x = Math.max(0, Math.min(640 - 160, e.clientX - dragOffset.x));
    pipPos.y = Math.max(0, Math.min(480 - 120, e.clientY - dragOffset.y));
    updatePipPosition();
  });
  window.addEventListener("mouseup", () => { dragging = false; localVideo.style.cursor = "grab"; });

  // Buttons
  if (hangupBtn) hangupBtn.onclick = hangup;

  // Unmute remote due to user gesture requirement on some browsers
  document.body.addEventListener("click", () => remoteVideo?.play().catch(() => {}));

  // Start
  start().catch(err => { setStatus("Init error: " + err.message); });

  async function start() {
    setStatus("Initializing camera...");
    try {
      localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
      localVideo.srcObject = localStream;
      localVideo.muted = true;
      updatePipPosition();

      if (!UPLOAD_ID) {
        console.warn("No callToken/upload_id set on page; WebRTC may still work, but uploads will fail.");
      }

      socket.emit("joinRoom", UPLOAD_ID);
      setStatus("Waiting for peer...");
    } catch (err) {
      setStatus("Camera/Mic error: " + err.message);
      alert(err.message);
      throw err;
    }
  }

  // Socket events (signaling)
  socket.on("joinedRoom", (data) => {
    isCaller = !!data.isCaller;
    createPeerConnection();
    if (isCaller) createAndSendOffer();
  });

  socket.on("peer-joined", () => {
    if (isCaller && peerConnection) createAndSendOffer();
  });

  socket.on("offer", async (offer) => {
    if (!peerConnection) createPeerConnection();
    await peerConnection.setRemoteDescription(new RTCSessionDescription(offer));
    const answer = await peerConnection.createAnswer();
    await peerConnection.setLocalDescription(answer);
    socket.emit("answer", { roomId: UPLOAD_ID, answer });
  });

  socket.on("answer", async (answer) => {
    await peerConnection.setRemoteDescription(new RTCSessionDescription(answer));
  });

  socket.on("ice-candidate", async (candidate) => {
    if (candidate && peerConnection) {
      try { await peerConnection.addIceCandidate(candidate); } catch (err) { console.error("ICE error:", err); }
    }
  });

  function createPeerConnection() {
    peerConnection = new RTCPeerConnection({ iceServers: [{ urls: "stun:stun.l.google.com:19302" }] });
    localStream.getTracks().forEach(t => peerConnection.addTrack(t, localStream));

    peerConnection.onicecandidate = (e) => {
      if (e.candidate) socket.emit("ice-candidate", { roomId: UPLOAD_ID, candidate: e.candidate });
    };

    peerConnection.ontrack = (e) => {
      remoteVideo.srcObject = e.streams[0];
      remoteVideo.muted = false;
      remoteVideo.volume = 1.0;
      remoteVideo.play().catch(() => {});
    };

    peerConnection.onconnectionstatechange = () => {
      const st = peerConnection.connectionState;
      if (st === "connected") {
        if (!callStarted) {
          callStarted = true;
          setStatus("Call connected");

          // ✅ Agent-only: only record+upload if we ARE the caller AND we have a JWT
          if (isCaller && window.Laravel?.jwtToken) {
            startPiPDrawing();
            startChunkedRecording();
          } else {
            console.log("Viewer-only mode (not caller or missing JWT) — no upload.");
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
    const offer = await peerConnection.createOffer();
    await peerConnection.setLocalDescription(offer);
    socket.emit("offer", { roomId: UPLOAD_ID, offer });
  }

  function hangup() {
    setStatus("Call Ended");
    callEnded = true; callStarted = false;
    try { peerConnection?.close(); } catch (e) {}
    localStream?.getTracks().forEach(t => t.stop());
    localVideo.srcObject = null;
    remoteVideo.srcObject?.getTracks?.().forEach(t => t.stop());
    remoteVideo.srcObject = null;
    stopPiPDrawing();
    stopRecording();
  }

  function startPiPDrawing() {
    mixedCanvas.width = 640; mixedCanvas.height = 480;
    function draw() {
      ctx.clearRect(0, 0, 640, 480);
      if (remoteVideo.readyState >= 2) ctx.drawImage(remoteVideo, 0, 0, 640, 480);
      if (localVideo.readyState >= 2) ctx.drawImage(localVideo, pipPos.x, pipPos.y, 160, 120);
      if (!callEnded) mixAnimationId = requestAnimationFrame(draw);
    }
    draw();
  }
  function stopPiPDrawing() { cancelAnimationFrame(mixAnimationId); }

  // === Chunked recording/upload ===
  function startChunkedRecording() {
    if (!callStarted || recording) return;
    if (!window.Laravel?.jwtToken) { console.warn("No JWT; skipping recording."); return; }

    setStatus("Recording...", true);
    recording = true;

    const stream = mixedCanvas.captureStream(30);
    recorder = new MediaRecorder(stream, { mimeType: "video/webm" });

    recorder.ondataavailable = async (e) => {
      if (!e.data || !e.data.size) return;
      const fd = new FormData();
      fd.append("upload_id", UPLOAD_ID);
      fd.append("seq", String(seq));
      fd.append("chunk", e.data, `part_${seq}.webm`);
      seq++;

      try {
        const res = await fetch(API + "/upload-chunk", {
          method: "POST",
          headers: { "Authorization": "Bearer " + window.Laravel.jwtToken },
          body: fd
        });
        if (!res.ok) {
          console.error("Chunk upload failed", res.status, await res.text());
        }
      } catch (err) {
        console.error("Chunk upload error", err);
      }
    };

    recorder.start(5000); // emit every 5 seconds
  }

  async function finalizeUpload() {
    if (finalized) return;
    finalized = true;

    if (!window.Laravel?.jwtToken) { // viewer-only or customer — do nothing
      console.log("No JWT — finalize skipped.");
      return;
    }

    try {
      const res = await fetch(API + "/finalize-upload", {
        method: "POST",
        headers: {
          "Authorization": "Bearer " + window.Laravel.jwtToken,
          "Content-Type": "application/json"
        },
        body: JSON.stringify({ upload_id: UPLOAD_ID, total_parts: seq })
      });
      const body = await res.text();
      if (!res.ok) throw new Error(`Finalize failed: ${res.status} ${body}`);
      console.log("Finalize ok:", body);
      setStatus("Upload complete!");
    } catch (err) {
      console.error(err);
      setStatus("Upload error");
    }
  }

  function stopRecording() {
    if (recorder && recording) {
      try { recorder.stop(); } catch (e) {}
      recording = false;
    }
    finalizeUpload();
  }

  window.addEventListener("beforeunload", finalizeUpload);
})();
