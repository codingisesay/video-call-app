{{-- <!DOCTYPE html>
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

 <script>
  // existing block that sets window.Laravel.callToken/apiUrl/jwtToken
  window.Laravel = {
    callToken: @json($meeting->meeting_token),
    apiUrl: @json(url('/api')),
    jwtToken: @json(session('jwt_token') ?? null),
  };

  // also try localStorage/sessionStorage fallback
  (function ensureJwt(){
    try {
      if (!window.Laravel.jwtToken) {
        const t = localStorage.getItem('dao_jwt') || sessionStorage.getItem('dao_jwt');
        if (t) window.Laravel.jwtToken = t;
      }
    } catch(e){}
  })();

  // NEW: receive JWT via postMessage from DAO app
  (function setupJwtPostMessage(){
    window.addEventListener('message', (evt) => {
      // Accept messages only from your DAO origin(s)
      const allowed = [
        'https://dao.payvance.co.in:8091',      // your DAO app origin (adjust if different)
        'https://your-dao-frontend.example',    // add any other allowed origins if needed
      ];
      if (!allowed.includes(evt.origin)) return;

      const msg = evt.data;
      if (msg && msg.type === 'DAO_JWT' && typeof msg.token === 'string' && msg.token.length > 20) {
        window.Laravel.jwtToken = msg.token;
        try { localStorage.setItem('dao_jwt', msg.token); } catch(e) {}
        console.log('JWT received via postMessage');
      }
    });
  })();
</script>

  <!-- Socket.io then your JS -->
  <script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
  <script src="{{ asset('js/video-call.js') }}"></script>
</body>
</html> --}}


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Video Call</title>
  <style>
    body { background: #f6f8fb; min-height: 100vh; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
    .video-call-header { text-align:center; margin-top:32px; color:#2d3a4b; font-weight:700; font-size:2rem; }
    .video-container { position:relative; width:700px; height:500px; background:#1a2233; border-radius:20px; margin:32px auto 0; overflow:hidden; box-shadow:0 12px 36px rgba(60,60,120,.18); }
    #remoteVideo { width:100%; height:100%; object-fit:cover; background:#000; }
    #localVideo { width:170px; height:130px; position:absolute; right:20px; top:20px; border-radius:16px; border:3px solid #fff; box-shadow:0 4px 18px rgba(0,0,0,.22); z-index:2; background:#222; }
    .status-badge { position:absolute; top:18px; left:18px; z-index:3; background:#3498db; color:#fff; padding:8px 16px; border-radius:12px; font-weight:700; }
    .status-badge.recording { background:#e74c3c; }
    .controls { position:absolute; bottom:20px; left:0; right:0; display:flex; justify-content:center; gap:12px; z-index:4; }
    .vc-btn { background:#3498db; color:#fff; border:none; border-radius:8px; padding:8px 16px; font-weight:600; cursor:pointer; }
    .vc-btn.end { background:#f39c12; }
    .vc-footer { text-align:center; margin:20px 0 40px; color:#888; }
    @media (max-width:800px){ .video-container{ width:95vw; height:60vw; min-width:320px; min-height:240px; } }
  </style>

  <!-- Make the room token & API URL available to JS immediately -->
  <script>
    // 1) Base object from server
    window.Laravel = {
      callToken: @json($meeting->meeting_token ?? null), // meeting_token OR upload_id
      apiUrl: @json(url('/api')),
      jwtToken: @json(session('jwt_token') ?? null),      // may be null
    };

    // 2) Try to hydrate jwt from URL query (?token=...) FIRST so it's ready before main JS
    (function seedJwtFromUrl() {
      try {
        var params = new URLSearchParams(window.location.search);
        var q = params.get('token') || params.get('jwt') || params.get('access_token');
        if (q && typeof q === 'string' && q.length > 20) {
          window.Laravel.jwtToken = q;
          try { localStorage.setItem('dao_jwt', q); } catch(e) {}
          try { sessionStorage.setItem('dao_jwt', q); } catch(e) {}
          console.log('[vcall] JWT picked from URL query');
        }
      } catch (e) {}
    })();

    // 3) If still missing, try local/session storage
    (function seedJwtFromStorage(){
      try {
        if (!window.Laravel.jwtToken) {
          var t = localStorage.getItem('dao_jwt') || sessionStorage.getItem('dao_jwt');
          if (t && t.length > 20) {
            window.Laravel.jwtToken = t;
            console.log('[vcall] JWT restored from storage');
          }
        }
      } catch (e) {}
    })();

    // 4) Also accept token via postMessage from DAO tab (works even without URL token)
    (function setupJwtPostMessage(){
      window.addEventListener('message', function(evt){
        // Allow only your known DAO origins (include dev + prod)
        var allowed = [
          'https://dao.payvance.co.in:8091',
          'https://dao.payvance.co.in',
          'https://localhost:5173',
          'http://localhost:5173'
        ];
        if (!allowed.includes(evt.origin)) return;

        var msg = evt.data;
        if (msg && msg.type === 'DAO_JWT' && typeof msg.token === 'string' && msg.token.length > 20) {
          window.Laravel.jwtToken = msg.token;
          try { localStorage.setItem('dao_jwt', msg.token); } catch(e) {}
          try { sessionStorage.setItem('dao_jwt', msg.token); } catch(e) {}
          console.log('[vcall] JWT received via postMessage');
        }
      });
    })();
  </script>
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

  <div class="vc-footer">Powered by Payvance DAO</div>

  <!-- Socket.io then your app script -->
  <script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
  <script src="{{ asset('js/video-call.js') }}"></script>
</body>
</html>

