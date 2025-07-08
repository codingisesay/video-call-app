const { Server } = require("socket.io");

const io = new Server(5000, {
  cors: {
    origin: "*"
  }
});

io.on("connection", socket => {
  console.log("New connection:", socket.id);

  socket.on("joinRoom", (roomId) => {
    socket.join(roomId);
    const roomSize = io.sockets.adapter.rooms.get(roomId)?.size || 0;
    console.log(`User joined room ${roomId}, size: ${roomSize}`);

    socket.emit("joinedRoom", {
      isCaller: roomSize === 1
    });

    if (roomSize > 1) {
      socket.to(roomId).emit("peer-joined");
    }
  });

  socket.on("offer", (data) => {
    console.log("Offer received, broadcasting in room", data.roomId);
    socket.to(data.roomId).emit("offer", data.offer);
  });

  socket.on("answer", (data) => {
    console.log("Answer received, broadcasting in room", data.roomId);
    socket.to(data.roomId).emit("answer", data.answer);
  });

  socket.on("ice-candidate", (data) => {
    console.log("ICE candidate received, broadcasting in room", data.roomId);
    socket.to(data.roomId).emit("ice-candidate", data.candidate);
  });

  socket.on("disconnect", () => {
    console.log("Disconnected:", socket.id);
  });
});

console.log("Listening on port 5000");