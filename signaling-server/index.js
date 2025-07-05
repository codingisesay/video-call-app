const { Server } = require("socket.io");

const io = new Server(5000, {
  cors: {
    origin: "*"
  }
});

io.on("connection", socket => {
  console.log("New connection!");

  socket.on("offer", data => {
    console.log("Offer received");
    socket.broadcast.emit("offer", data);
  });

  socket.on("answer", data => {
    console.log("Answer received");
    socket.broadcast.emit("answer", data);
  });

  socket.on("ice-candidate", data => {
    console.log("ICE Candidate received");
    socket.broadcast.emit("ice-candidate", data);
  });
});

console.log("Listening on port 5000");
