const { Server } = require("socket.io");

const io = new Server(5000, {
  cors: {
    origin: "*"
  }
});

io.on("connection", socket => {
  console.log("New connection:", socket.id);

  socket.on("joinRoom", (roomId) => {
    // Check room size BEFORE joining
    const room = io.sockets.adapter.rooms.get(roomId);
    const roomSize = room ? room.size : 0;

    // The first person becomes the caller
    const isCaller = roomSize === 0;

    socket.join(roomId);

    console.log(`User joined room ${roomId}. Caller? ${isCaller}`);

    socket.emit("joinedRoom", {
      isCaller: isCaller
    });

    // Notify others that a peer joined
    if (roomSize > 0) {
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
