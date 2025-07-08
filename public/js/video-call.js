const socket = io("https://vcall.payvance.co.in", {
  path: "/socket.io",
  transports: ["websocket"]
});

let localStream;
let peerConnection;
let recorder;
let recordedChunks = [];
let mixedCanvas = document.getElementById("mixedCanvas");
let ctx = mixedCanvas.getContext("2d");
let mixAnimationId;
let isCaller = false;
let callStarted = false;
let recording = false;
let callEnded = false;

let pipPos = { x: 420, y: 300 };
let dragging = false;
let dragOffset = { x: 0, y: 0 };

const localVideo = document.getElementById("localVideo");
const remoteVideo = document.getElementById("remoteVideo");
const statusBadge = document.getElementById("statusBadge");

function setStatus(text, isRecording = false) {
  statusBadge.textContent = text;
  if (isRecording) statusBadge.classList.add("recording");
  else statusBadge.classList.remove("recording");
}

function updatePipPosition() {
  localVideo.style.left = pipPos.x + "px";
  localVideo.style.top = pipPos.y + "px";
}

localVideo.addEventListener("mousedown", (e) => {
  dragging = true;
  dragOffset.x = e.clientX - pipPos.x;
  dragOffset.y = e.clientY - pipPos.y;
});
window.addEventListener("mousemove", (e) => {
  if (dragging) {
    pipPos.x = Math.max(0, Math.min(640 - 160, e.clientX - dragOffset.x));
    pipPos.y = Math.max(0, Math.min(480 - 120, e.clientY - dragOffset.y));
    updatePipPosition();
  }
});
window.addEventListener("mouseup", () => {
  dragging = false;
});

document.getElementById("hangupBtn").onclick = hangup;

// Add user gesture fallback for audio
document.body.addEventListener("click", () => {
  if (remoteVideo) {
    remoteVideo.play().catch(e => console.error("Playback error:", e));
  }
});

start();

async function start() {
  setStatus("Initializing camera...");
  try {
    localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
    localVideo.srcObject = localStream;
    localVideo.muted = true;

    socket.emit("joinRoom", window.Laravel.callToken);
    setStatus("Waiting for peer...");
  } catch (err) {
    setStatus("Camera/Mic error: " + err.message);
    alert(err.message);
  }
}

socket.on("joinedRoom", (data) => {
  console.log("joinedRoom", data);
  isCaller = data.isCaller;
  if (isCaller) {
    createPeerConnection();
    createAndSendOffer();
  }
});

socket.on("peer-joined", () => {
  console.log("peer-joined received");
  if (isCaller && peerConnection) {
    console.log("Sending offer because peer joined...");
    createAndSendOffer();
  }
});

socket.on("offer", async (offer) => {
  console.log("Received offer");
  if (!peerConnection) createPeerConnection();
  await peerConnection.setRemoteDescription(new RTCSessionDescription(offer));
  const answer = await peerConnection.createAnswer();
  await peerConnection.setLocalDescription(answer);
  socket.emit("answer", {
    roomId: window.Laravel.callToken,
    answer: answer
  });
});

socket.on("answer", async (answer) => {
  console.log("Received answer");
  await peerConnection.setRemoteDescription(new RTCSessionDescription(answer));
});

socket.on("ice-candidate", async (candidate) => {
  console.log("Received ICE candidate");
  if (candidate && peerConnection) {
    try {
      await peerConnection.addIceCandidate(candidate);
      console.log("Added ICE candidate");
    } catch (err) {
      console.error("Error adding ICE:", err);
    }
  }
});

function createPeerConnection() {
  peerConnection = new RTCPeerConnection({
    iceServers: [
      { urls: "stun:stun.l.google.com:19302" }
    ]
  });

  localStream.getTracks().forEach((track) => {
    peerConnection.addTrack(track, localStream);
  });

  peerConnection.onicecandidate = (e) => {
    if (e.candidate) {
      socket.emit("ice-candidate", {
        roomId: window.Laravel.callToken,
        candidate: e.candidate
      });
    }
  };

  peerConnection.ontrack = (e) => {
    console.log("Received remote track");
    remoteVideo.srcObject = e.streams[0];
    remoteVideo.muted = false;
    remoteVideo.volume = 1.0;

    remoteVideo.play().catch(err => {
      console.error("Remote video playback error:", err);
    });

    console.log("Remote audio tracks:", e.streams[0].getAudioTracks());
  };

  peerConnection.onconnectionstatechange = () => {
    console.log("Peer connection state:", peerConnection.connectionState);
    if (peerConnection.connectionState === "connected") {
      if (!callStarted) {
        callStarted = true;
        setStatus("Call connected");
        startPiPDrawing();
        startRecording();
      }
    } else if (["failed", "disconnected", "closed"].includes(peerConnection.connectionState)) {
      setStatus("Call disconnected");
      stopPiPDrawing();
      stopRecording();
    }
  };
}

async function createAndSendOffer() {
  console.log("Creating and sending offer...");
  const offer = await peerConnection.createOffer();
  await peerConnection.setLocalDescription(offer);
  socket.emit("offer", {
    roomId: window.Laravel.callToken,
    offer: offer
  });
}

function hangup() {
  setStatus("Call Ended");
  callEnded = true;
  callStarted = false;

  if (peerConnection) peerConnection.close();

  if (localStream) {
    localStream.getTracks().forEach((t) => t.stop());
    localVideo.srcObject = null;
  }
  if (remoteVideo.srcObject) {
    remoteVideo.srcObject.getTracks?.().forEach((t) => t.stop());
    remoteVideo.srcObject = null;
  }
  stopPiPDrawing();
  stopRecording();
}

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

function startRecording() {
  if (!callStarted) return;
  if (recording) return;
  setStatus("Recording...", true);
  recording = true;
  recordedChunks = [];

  const stream = mixedCanvas.captureStream(30);
  recorder = new MediaRecorder(stream, { mimeType: "video/webm" });

  recorder.ondataavailable = (e) => {
    if (e.data.size > 0) recordedChunks.push(e.data);
  };

  recorder.onstop = () => {
    const blob = new Blob(recordedChunks, { type: "video/webm" });
    uploadRecording(blob);
  };

  recorder.start();
}

function stopRecording() {
  if (recorder && recording) {
    setStatus("Uploading...");
    recorder.stop();
    recording = false;
  }
}

function uploadRecording(blob) {
  const formData = new FormData();
  formData.append("video", blob, "call_recording.webm");
  formData.append("call_token", window.Laravel.callToken);
  formData.append("duration", 120);
  formData.append("started_at", new Date().toISOString());
  formData.append("ended_at", new Date().toISOString());

  fetch(window.Laravel.apiUrl + "/upload-video", {
    method: "POST",
    body: formData
  })
    .then((res) => res.json())
    .then((data) => {
      setStatus("Upload successful!");
      console.log("Upload complete:", data);
    })
    .catch((err) => {
      setStatus("Upload error");
      console.error(err);
    });
}
