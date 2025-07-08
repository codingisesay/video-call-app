<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Video Call</title>
    <style>
        /* ... (keep all your existing styles untouched) ... */
    </style>
</head>
<body>
    <div class="video-call-header">Video Call</div>
    <div class="video-container">
        <div id="statusBadge" class="status-badge">Idle</div>
        <video id="remoteVideo" autoplay playsinline></video>
        <video id="localVideo" autoplay muted playsinline></video>
        <canvas id="mixedCanvas" style="display:none;"></canvas>
        <div class="controls">
            <button id="hangupBtn" class="vc-btn end">End Call</button>
        </div>
    </div>
    <div class="vc-footer">
        <span>Powered by Payvance DAO</span>
    </div>
    <script>
        window.Laravel = {
            callToken: "{{ $meeting->meeting_token }}",
            apiUrl: "{{ url('/api') }}"
        }
    </script>
    <script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
    <script src="{{ asset('js/video-call.js') }}"></script>
</body>
</html>
