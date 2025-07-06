<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Video Call</title>
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        .video-container {
            position: relative;
            width: 640px;
            height: 480px;
            background: #222;
            border-radius: 18px;
            box-shadow: 0 8px 32px rgba(60,60,120,0.18);
            overflow: hidden;
            margin: 40px auto 0 auto;
        }
        #remoteVideo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            background: #000;
        }
        #localVideo {
            width: 160px;
            height: 120px;
            position: absolute;
            left: 420px;
            top: 300px;
            border-radius: 16px;
            border: 3px solid #fff;
            box-shadow: 0 2px 12px rgba(0,0,0,0.18);
            cursor: grab;
            z-index: 20;
            background: #222;
            transition: box-shadow 0.2s;
        }
        .controls {
            position: absolute;
            bottom: 18px;
            left: 0;
            width: 100%;
            display: flex;
            justify-content: center;
            gap: 18px;
            z-index: 30;
        }
        .status-badge {
            position: absolute;
            top: 16px;
            left: 16px;
            z-index: 10;
            background: #3498db;
            color: #fff;
            padding: 6px 18px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .status-badge.recording {
            background: #e74c3c;
        }
    </style>
</head>
<body>
    <h2 style="text-align:center; margin-top:30px; color:#2d3a4b; font-weight:700;">Video Call Page</h2>
    <div class="video-container">
        <div id="statusBadge" class="status-badge">Idle</div>
        <video id="remoteVideo" autoplay playsinline></video>
        <video id="localVideo" autoplay muted playsinline></video>
        <canvas id="mixedCanvas" style="display:none;"></canvas>
        <div class="controls">
            <button id="startCallBtn" style="background:#27ae60;">Start Call</button>
            <button id="hangupBtn" style="background:#e67e22;">End Call</button>
            <button id="startRecordBtn" style="background:#2980b9;">Start Recording</button>
            <button id="stopRecordBtn" style="background:#c0392b;">End & Upload Recording</button>
        </div>
    </div>
    <div style="text-align:center; margin-top:30px; color:#888; font-size:15px;">
        <span>Powered by Payvance DAO</span>
    </div>
    <script>
        window.Laravel = {
            callToken: "{{ $meeting->meeting_token }}",
            apiUrl: "{{ url('/api') }}"
        }
    </script>
    <script src="https://vcall.payvance.co.in/signalling/socket.io/socket.io.js"></script>
    <script src="{{ asset('js/video-call.js') }}"></script>
</body>
</html>