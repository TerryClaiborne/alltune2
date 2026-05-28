#pragma once

#include "Config.hpp"
#include "Logger.hpp"
#include "TLVPacket.hpp"
#include "UdpSocket.hpp"

#include <atomic>
#include <condition_variable>
#include <mutex>
#include <optional>
#include <queue>
#include <thread>

class TLVBridge {
public:
    TLVBridge(const Config& config, Logger& log);
    ~TLVBridge();

    bool start();
    void stop();
    bool popOutgoing(TLVPacket& packet, int timeoutMs);
    void pushIncoming(const TLVPacket& packet);
    std::string currentTalkgroup() const;
    void sendTalkgroupTune(const std::string& tg, const std::string& why);
    void sendRemoteCommand(const std::string& cmd, const std::string& why);
    void sendBeginTx(std::uint32_t srcId, std::uint32_t dstId, int slot, bool privateCall);
    void sendAmbe72(const std::vector<std::uint8_t>& ambe27);
    void sendEndTx();
private:
    const Config& config_;
    Logger& log_;
    UdpSocket tlvRxSocket_;
    UdpSocket tlvTxSocket_;
    int tlvRxPort_ = 0;
    int tlvTimeoutMs_ = 1000;
    std::string tlvTxHost_ = "127.0.0.1";
    int tlvTxPort_ = 0;
    bool txConfigured_ = false;
    std::atomic<bool> stop_{false};
    std::thread serviceThread_;
    std::thread queueThread_;
    mutable std::mutex queueMutex_;
    std::condition_variable queueCv_;
    std::queue<TLVPacket> outboundQueue_;
    mutable std::mutex stateMutex_;
    std::string currentTg_;
    std::uint32_t repeaterId_ = 0;
    int activeRxSlot_ = 2;

    void serviceTlvConnection();
    void processTlvQueue();
    void handlePacket(const TLVPacket& packet);
    bool ensureTxTarget(const std::string& why);
    bool sendPacketToBridge(const TLVPacket& packet, const std::string& why);
    static std::optional<TLVPacket> parse(const std::vector<std::uint8_t>& raw);
    static std::vector<std::uint8_t> encode(const TLVPacket& packet);
};
