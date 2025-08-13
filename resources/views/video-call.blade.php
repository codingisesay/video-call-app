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
      font-family: 'Segoe UI', 'Roboto', Arial, sans-serif;
    }
    .video-call-header {
      text-align: center;
      margin-top: 36px;
      color: #2d3a4b;
      font-weight: 700;
      font-size: 2.2rem;
      letter-spacing: 1px;
    }
    .video-container {
      position: relative;
      width: 700px;
      height: 500px;
      background: #1a2233;
      border-radius: 22px;
      box-shadow: 0 12px 36px rgba(60,60,120,0.18);
      overflow: hidden;
      margin: 40px auto 0 auto;
      border: 1.5px solid #e0e6ed;
    }
    #remoteVideo {
      width: 100%;
      height: 100%;
      object-fit: cover;
      background: #000;
      border-radius: 22px;
    }
    #localVideo {
      width: 170px;
      height: 130px;
      position: absolute;
      right: 24px;
      top: 24px;
      border-radius: 18px;
      border: 3px solid #fff;
      box-shadow: 0 4px 18px rgba(0,0,0,0.22);
      cursor: grab;
      z-index: 20;
      background: #222;
      transition: box-shadow 0.2s, border 0.2s;
    }
    #localVideo:active { box-shadow: 0 2px 8px rgba(0,0,0,0.18); border: 3px solid #3498db; }
    .vc-btn {
      background: linear-gradient(90deg, #3498db 0%, #6dd5fa 100%);
      color: #fff; border: none; border-radius: 8px;
      padding: 8px 20px; font-weight: 600; font-size: 0.98rem; cursor: pointer;
      box-shadow: 0 1px 6px rgba(52,152,219,0.10);
      transition: background 0.2s, box-shadow 0.2s, transform 0.1s; outline: none; min-width: 110px;
    }
    .vc-btn:active { background: linear-gradient(90deg, #2980b9 0%, #3498db 100%); transform: scale(0.97); }
    .vc-btn.end { background: linear-gradient(90deg, #e67e22 0%, #f7971e 100%); }
    .controls {
      position: absolute; bottom: 24px; left: 0; width: 100%;
      display: flex; justify-content: center; gap: 14px; z-index: 30;
    }
    .status-badge {
      position: absolute; top: 22px; left: 22px; z-index: 10;
      background: #3498db; color: #fff; padding: 8px 26px; border-radius: 14px;
      font-weight: 700; font-size: 1.02rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      letter-spacing: 0.5px; transition: background 0.2s;
    }
    .status-badge.recording { background: #e74c3c; }
    .vc-footer { text-align: center; margin-top: 38px; color: #888; font-size: 1.04rem; letter-spacing: 0.2px; }
    @media (max-width: 800px) {
      .video-container { width: 98vw; height: 60vw; min-width: 320px; min-height: 240px; max-width: 99vw; max-height: 80vw; }
      #localVideo { right: 12px; top: 12px; left: unset; bottom: unset; }
    }
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

  <!-- App context -->
  <script>
    window.Laravel = {
      callToken: @json($meeting->meeting_token),
      apiUrl: @json(url('/api')),
      // Agent pages can inject/store JWT; customer pages normally won't have it.
      jwtToken: @json(session('jwt_token') ?? null),
    };
    // Fallback: try to read token stored by your app (optional, but handy for agents)
    (function ensureJwt(){
      try {
        if (!window.Laravel.jwtToken) {
          const t = localStorage.getItem('dao_jwt') || sessionStorage.getItem('dao_jwt');
          if (t) window.Laravel.jwtToken = t;
        }
      } catch(e){}
    })();
  </script>

  <!-- Socket.io then your JS -->
  <script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
  <script src="{{ asset('js/video-call.js') }}"></script>
</body>
</html>
