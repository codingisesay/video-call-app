// const socket = io("wss://vcall.payvance.co.in/signalling");
const socket = io("https://vcall.payvance.co.in", {
  path: "/signalling/socket.io",
  transports: ["websocket", "polling"]
});

let localStream;
let peerConnection;
let recorder;
let recordedChunks = [];
let mixedCanvas = document.getElementById("mixedCanvas");
let ctx = mixedCanvas.getContext("2d");
let mixAnimationId;
let callStarted = false;
let recording = false;
let callEnded = false;

// PiP drag state
let pipPos = { x: 420, y: 300 };
let dragging = false;
let dragOffset = { x: 0, y: 0 };

const localVideo = document.getElementById("localVideo");
const remoteVideo = document.getElementById("remoteVideo");
const statusBadge = document.getElementById("statusBadge");

function setStatus(text, recording = false) {
    statusBadge.textContent = text;
    if (recording) statusBadge.classList.add('recording');
    else statusBadge.classList.remove('recording');
}

function updatePipPosition() {
    localVideo.style.left = pipPos.x + "px";
    localVideo.style.top = pipPos.y + "px";
}

// PiP drag handlers
localVideo.addEventListener('mousedown', function(e) {
    dragging = true;
    dragOffset.x = e.clientX - pipPos.x;
    dragOffset.y = e.clientY - pipPos.y;
});
window.addEventListener('mousemove', function(e) {
    if (dragging) {
        pipPos.x = Math.max(0, Math.min(640 - 160, e.clientX - dragOffset.x));
        pipPos.y = Math.max(0, Math.min(480 - 120, e.clientY - dragOffset.y));
        updatePipPosition();
    }
});
window.addEventListener('mouseup', function() {
    dragging = false;
});

document.getElementById("startCallBtn").onclick = startCall;
document.getElementById("startRecordBtn").onclick = startRecording;
document.getElementById("stopRecordBtn").onclick = stopRecording;
document.getElementById("hangupBtn").onclick = hangup;

async function startCall() {
    setStatus("Requesting camera/mic...");
    try {
        localStream = await navigator.mediaDevices.getUserMedia({
            video: true,
            audio: true
        });
        localVideo.srcObject = localStream;
        localVideo.muted = true;

        peerConnection = new RTCPeerConnection({
            iceServers: [
                { urls: "stun:stun.l.google.com:19302" }
            ]
        });

        localStream.getTracks().forEach(track => {
            peerConnection.addTrack(track, localStream);
        });

        peerConnection.ontrack = e => {
            remoteVideo.srcObject = e.streams[0];
        };

        peerConnection.onicecandidate = e => {
            if (e.candidate) {
                socket.emit("ice-candidate", e.candidate);
            }
        };

        const offer = await peerConnection.createOffer();
        await peerConnection.setLocalDescription(offer);
        socket.emit("offer", offer);

        callStarted = true;
        callEnded = false;
        setStatus("Call Started");
        startPiPDrawing();
    } catch (err) {
        setStatus("Camera/Mic error: " + err.message);
        alert("Camera/Mic error: " + err.message);
    }
}

socket.on("offer", async offer => {
    localStream = await navigator.mediaDevices.getUserMedia({
        video: true,
        audio: true
    });
    localVideo.srcObject = localStream;
    localVideo.muted = true;

    peerConnection = new RTCPeerConnection({
        iceServers: [
            { urls: "stun:stun.l.google.com:19302" }
        ]
    });

    localStream.getTracks().forEach(track => {
        peerConnection.addTrack(track, localStream);
    });

    // peerConnection.ontrack = e => {
    //     remoteVideo.srcObject = e.streams[0];
    // };

    peerConnection.ontrack = e => {
    console.log("ontrack event:", e);
    if (!remoteVideo.srcObject) {
        remoteVideo.srcObject = e.streams[0];
    }
};
    await peerConnection.setRemoteDescription(new RTCSessionDescription(offer));
    const answer = await peerConnection.createAnswer();
    await peerConnection.setLocalDescription(answer);
    socket.emit("answer", answer);

    callStarted = true;
    callEnded = false;
    setStatus("Call Started");
    startPiPDrawing();
});

socket.on("answer", async answer => {
    await peerConnection.setRemoteDescription(new RTCSessionDescription(answer));
});

socket.on("ice-candidate", async candidate => {
    if (candidate) {
        await peerConnection.addIceCandidate(candidate);
    }
});

function hangup() {
    setStatus("Call Ended");
    callStarted = false;
    callEnded = true;
    if (peerConnection) peerConnection.close();
    if (localStream) localStream.getTracks().forEach(track => track.stop());
    if (remoteVideo.srcObject) {
        remoteVideo.srcObject.getTracks?.().forEach(track => track.stop());
        remoteVideo.srcObject = null;
    }
    if (localVideo.srcObject) {
        localVideo.srcObject.getTracks?.().forEach(track => track.stop());
        localVideo.srcObject = null;
    }
    stopPiPDrawing();
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
    setStatus("Recording...", true);
    recording = true;
    recordedChunks = [];
    const stream = mixedCanvas.captureStream(30);
    recorder = new MediaRecorder(stream, {
        mimeType: "video/webm"
    });
    recorder.ondataavailable = e => {
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
        .then(response => response.json())
        .then(data => {
            setStatus("Upload successful!");
            console.log("Upload successful:", data);
        })
        .catch(err => {
            setStatus("Upload error");
            console.error(err);
        });
}