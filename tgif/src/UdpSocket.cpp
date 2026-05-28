#include "UdpSocket.hpp"

#include <arpa/inet.h>
#include <cerrno>
#include <cstring>
#include <netdb.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <unistd.h>

UdpSocket::~UdpSocket() { close(); }

static void setTimeout(int fd, int timeoutMs) {
    timeval tv{};
    tv.tv_sec = timeoutMs / 1000;
    tv.tv_usec = (timeoutMs % 1000) * 1000;
    setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof(tv));
}

bool UdpSocket::openConnected(const std::string& host, int port, int timeoutMs, int localBindPort, std::string& error) {
    close();
    fd_ = socket(AF_INET, SOCK_DGRAM, 0);
    if (fd_ < 0) {
        error = std::string("socket failed: ") + std::strerror(errno);
        return false;
    }
    if (localBindPort > 0) {
        sockaddr_in local{};
        local.sin_family = AF_INET;
        local.sin_addr.s_addr = htonl(INADDR_ANY);
        local.sin_port = htons(static_cast<uint16_t>(localBindPort));
        if (bind(fd_, reinterpret_cast<sockaddr*>(&local), sizeof(local)) != 0) {
            error = std::string("bind failed: ") + std::strerror(errno);
            close();
            return false;
        }
    }
    addrinfo hints{};
    hints.ai_family = AF_INET;
    hints.ai_socktype = SOCK_DGRAM;
    addrinfo* res = nullptr;
    auto portText = std::to_string(port);
    if (getaddrinfo(host.c_str(), portText.c_str(), &hints, &res) != 0 || !res) {
        error = "getaddrinfo failed for " + host;
        close();
        return false;
    }
    if (connect(fd_, res->ai_addr, res->ai_addrlen) != 0) {
        error = std::string("connect failed: ") + std::strerror(errno);
        freeaddrinfo(res);
        close();
        return false;
    }
    freeaddrinfo(res);
    connected_ = true;
    setTimeout(fd_, timeoutMs);
    return true;
}

bool UdpSocket::openBound(int bindPort, int timeoutMs, std::string& error) {
    close();
    fd_ = socket(AF_INET, SOCK_DGRAM, 0);
    if (fd_ < 0) {
        error = std::string("socket failed: ") + std::strerror(errno);
        return false;
    }
    sockaddr_in local{};
    local.sin_family = AF_INET;
    local.sin_addr.s_addr = htonl(INADDR_ANY);
    local.sin_port = htons(static_cast<uint16_t>(bindPort));
    if (bind(fd_, reinterpret_cast<sockaddr*>(&local), sizeof(local)) != 0) {
        error = std::string("bind failed: ") + std::strerror(errno);
        close();
        return false;
    }
    connected_ = false;
    setTimeout(fd_, timeoutMs);
    return true;
}

bool UdpSocket::sendPacket(const std::vector<std::uint8_t>& packet, std::string& error) {
    if (fd_ < 0 || !connected_) {
        error = "socket not connected";
        return false;
    }
    auto n = send(fd_, packet.data(), packet.size(), 0);
    if (n < 0 || static_cast<size_t>(n) != packet.size()) {
        error = std::string("send failed: ") + std::strerror(errno);
        return false;
    }
    return true;
}

bool UdpSocket::sendToLastPeer(const std::vector<std::uint8_t>& packet, std::string& error) {
    if (fd_ < 0 || lastPeerLen_ == 0) {
        error = "no peer recorded";
        return false;
    }
    auto n = sendto(fd_, packet.data(), packet.size(), 0, reinterpret_cast<sockaddr*>(&lastPeer_), lastPeerLen_);
    if (n < 0 || static_cast<size_t>(n) != packet.size()) {
        error = std::string("sendto failed: ") + std::strerror(errno);
        return false;
    }
    return true;
}

std::optional<std::vector<std::uint8_t>> UdpSocket::receivePacket(std::string& error) {
    if (fd_ < 0) {
        error = "socket not open";
        return std::nullopt;
    }
    std::vector<std::uint8_t> buffer(2048);
    sockaddr_storage peer{};
    socklen_t peerLen = sizeof(peer);
    auto n = recvfrom(fd_, buffer.data(), buffer.size(), 0, reinterpret_cast<sockaddr*>(&peer), &peerLen);
    if (n < 0) {
        if (errno == EAGAIN || errno == EWOULDBLOCK) return std::vector<std::uint8_t>{};
        error = std::string("recvfrom failed: ") + std::strerror(errno);
        return std::nullopt;
    }
    lastPeer_ = peer;
    lastPeerLen_ = peerLen;
    buffer.resize(static_cast<size_t>(n));
    return buffer;
}

void UdpSocket::close() {
    if (fd_ >= 0) {
        ::close(fd_);
        fd_ = -1;
    }
    connected_ = false;
    lastPeerLen_ = 0;
}
