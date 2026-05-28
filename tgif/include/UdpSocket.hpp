#include <sys/socket.h>

#pragma once

#include <cstdint>
#include <optional>
#include <string>
#include <vector>

class UdpSocket {
public:
    ~UdpSocket();
    bool openConnected(const std::string& host, int port, int timeoutMs, int localBindPort, std::string& error);
    bool openBound(int bindPort, int timeoutMs, std::string& error);
    bool sendPacket(const std::vector<std::uint8_t>& packet, std::string& error);
    bool sendToLastPeer(const std::vector<std::uint8_t>& packet, std::string& error);
    std::optional<std::vector<std::uint8_t>> receivePacket(std::string& error);
    void close();
private:
    int fd_ = -1;
    bool connected_ = false;
    struct sockaddr_storage lastPeer_{};
    socklen_t lastPeerLen_ = 0;
};
