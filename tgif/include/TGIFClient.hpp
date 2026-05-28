#pragma once

#include "BackendControl.hpp"
#include "Config.hpp"
#include "Logger.hpp"
#include "TgifCodec.hpp"
#include "TLVBridge.hpp"
#include "UdpSocket.hpp"

#include <atomic>
#include <chrono>
#include <cstdint>
#include <string>
#include <thread>
#include <vector>

class TGIFClient {
public:
    TGIFClient(const Config& config, Logger& log, TLVBridge& tlv, BackendControl& backend);
    ~TGIFClient();

    bool init();
    bool start();
    void stop();
private:
    enum class Phase {
        Closed,
        Opened,
        LoginRequested,
        AuthRequested,
        ConfigRequested,
        OptionsRequested,
        Connected,
        LoginFailed
    };

    struct TxState {
        bool active = false;
        std::uint32_t srcId = 0;
        std::uint32_t dstId = 0;
        bool slot2 = false;
        bool privateCall = false;
        std::uint32_t streamId = 0;
        std::uint8_t seq = 0;
        int burst = 0;
    };

    const Config& config_;
    Logger& log_;
    TLVBridge& tlv_;
    BackendControl& backend_;
    UdpSocket socket_;
    std::atomic<bool> stop_{false};
    std::thread networkThread_;

    std::uint32_t radioId_ = 0;
    std::string host_;
    int port_ = 62031;
    int recvTimeoutMs_ = 1000;
    int localBindPort_ = 0;
    int keepaliveSeconds_ = 10;
    int softRefreshTriggerMissed_ = 2;
    int maxMissed_ = 5;
    int reconnectDelaySeconds_ = 5;
    int rxIdleEndMs_ = 1500;
    std::string securityKey_;
    std::string startupTg_;
    std::string options_;
    Phase phase_ = Phase::Closed;
    std::chrono::steady_clock::time_point lastPing_{};
    std::chrono::steady_clock::time_point lastPong_{};
    std::chrono::steady_clock::time_point lastServerActivity_{};
    std::chrono::steady_clock::time_point softRefreshCooldownUntil_{};
    int sentPings_ = 0;
    int ackedPings_ = 0;
    bool refreshInFlight_ = false;
    bool pingOutstanding_ = false;
    bool connectedOnce_ = false;
    std::vector<std::uint8_t> activeRxStream_;
    bool rxActive_ = false;
    std::chrono::steady_clock::time_point rxStreamStart_{};
    std::chrono::steady_clock::time_point lastRxPacket_{};
    TxState tx_{};

    void handleDmrd(const std::vector<std::uint8_t>& packet);
    static std::vector<std::uint8_t> extractAmbe72FromDmrd(const std::vector<std::uint8_t>& packet);
    void processOutgoingTlv(const TLVPacket& packet);
    void networkLoop();
    bool openSocket();
    bool sendLogin(const std::string& why = "login");
    bool sendAuthorization(const std::vector<std::uint8_t>& salt);
    bool sendConfig(const std::string& why = "config");
    bool sendOptions(const std::string& why = "");
    bool sendPing();
    bool sendClose();
    bool startSoftRefresh(const std::string& why);
    bool hardReconnect();
    void onConnected(const std::string& why);
    std::string effectiveTalkgroup() const;
    bool sendRaw(const std::vector<std::uint8_t>& packet, const std::string& what);
    static std::uint32_t randomStreamId();
};
