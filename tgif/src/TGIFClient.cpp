#include "TGIFClient.hpp"

#include <iomanip>
#include <random>
#include <sstream>
#include <thread>
#include <vector>

namespace {
constexpr std::uint8_t TAG_BEGIN_TX   = 0x00;
constexpr std::uint8_t TAG_END_TX     = 0x02;
constexpr std::uint8_t TAG_TG_TUNE    = 0x03;
constexpr std::uint8_t TAG_REMOTE_CMD = 0x05;
constexpr std::uint8_t TAG_AMBE_72    = 0x07;

std::uint32_t parseRadioId(const std::string& value) {
    try { return static_cast<std::uint32_t>(std::stoul(value)); }
    catch (...) { return 0; }
}

std::uint32_t read24(const std::vector<std::uint8_t>& data, std::size_t off) {
    if (data.size() < off + 3) return 0;
    return (static_cast<std::uint32_t>(data[off]) << 16) |
           (static_cast<std::uint32_t>(data[off + 1]) << 8) |
           static_cast<std::uint32_t>(data[off + 2]);
}

std::string hexBytes(const std::vector<std::uint8_t>& data, std::size_t off, std::size_t len) {
    std::ostringstream oss;
    oss << std::hex << std::setfill('0');
    for (std::size_t i = 0; i < len && off + i < data.size(); ++i) {
        if (i) oss << ' ';
        oss << std::setw(2) << static_cast<int>(data[off + i]);
    }
    return oss.str();
}

std::string hexByte(std::uint8_t value) {
    std::ostringstream oss;
    oss << std::hex << std::setfill('0') << std::setw(2) << static_cast<int>(value);
    return oss.str();
}

std::string secondsText(std::chrono::steady_clock::duration d) {
    auto ms = std::chrono::duration_cast<std::chrono::milliseconds>(d).count();
    std::ostringstream oss;
    oss << std::fixed << std::setprecision(2) << (static_cast<double>(ms) / 1000.0);
    return oss.str();
}
}

TGIFClient::TGIFClient(const Config& config, Logger& log, TLVBridge& tlv, BackendControl& backend)
    : config_(config), log_(log), tlv_(tlv), backend_(backend) {}

TGIFClient::~TGIFClient() { stop(); }

bool TGIFClient::init() {
    radioId_ = parseRadioId(config_.get("identity", "hotspot_radio_id", config_.get("identity", "dmr_id", "0")));
    host_ = config_.get("tgif", "host", "tgif.network");
    port_ = config_.getInt("tgif", "port", 62031);
    recvTimeoutMs_ = config_.getInt("behavior", "receive_timeout_ms", 1000);
    localBindPort_ = config_.getInt("network", "local_bind_port", 0);
    keepaliveSeconds_ = config_.getInt("behavior", "keepalive_seconds", 10);
    softRefreshTriggerMissed_ = config_.getInt("behavior", "soft_refresh_trigger_missed", 2);
    maxMissed_ = config_.getInt("behavior", "max_missed", 5);
    reconnectDelaySeconds_ = config_.getInt("behavior", "reconnect_delay_seconds", 5);
    rxIdleEndMs_ = config_.getInt("behavior", "rx_idle_end_ms", 1500);
    if (rxIdleEndMs_ < 500) rxIdleEndMs_ = 500;
    if (rxIdleEndMs_ > 5000) rxIdleEndMs_ = 5000;
    securityKey_ = config_.get("tgif", "security_key", "");
    startupTg_ = config_.get("tgif", "startup_tg", "9990");
    options_ = config_.get("tgif", "options", "");
    if (radioId_ == 0 || securityKey_.empty()) {
        log_.error("missing hotspot radio id or TGIF security key");
        return false;
    }
    return true;
}

bool TGIFClient::start() {
    stop_ = false;
    networkThread_ = std::thread(&TGIFClient::networkLoop, this);
    return true;
}

void TGIFClient::stop() {
    stop_ = true;
    socket_.close();
    if (networkThread_.joinable()) networkThread_.join();
}

std::string TGIFClient::effectiveTalkgroup() const {
    auto tg = tlv_.currentTalkgroup();
    return tg.empty() ? startupTg_ : tg;
}

std::uint32_t TGIFClient::randomStreamId() {
    static std::mt19937 rng{std::random_device{}()};
    std::uniform_int_distribution<std::uint32_t> dist(1, 0xFFFFFFFFu);
    return dist(rng);
}

bool TGIFClient::sendRaw(const std::vector<std::uint8_t>& packet, const std::string& what) {
    std::string error;
    if (!socket_.sendPacket(packet, error)) {
        log_.warn(what + " send failed: " + error);
        return false;
    }
    return true;
}

void TGIFClient::handleDmrd(const std::vector<std::uint8_t>& packet) {
    if (packet.size() < 53) return;
    auto read24p = [&](std::size_t off) -> std::uint32_t {
        return (static_cast<std::uint32_t>(packet[off]) << 16) |
               (static_cast<std::uint32_t>(packet[off + 1]) << 8) |
               static_cast<std::uint32_t>(packet[off + 2]);
    };
    std::uint32_t srcId = read24p(5);
    std::uint32_t dstId = read24p(8);
    std::uint8_t bits = packet[15];
    int slot = config_.getInt("tlv", "inbound_slot", 2);
    if (slot < 1 || slot > 2) slot = 2;
    bool privateCall = (bits & 0x40) != 0;
    int frameType = (bits & 0x30) >> 4;
    int dtypeVseq = (bits & 0x0F);
    std::vector<std::uint8_t> stream(packet.begin() + 16, packet.begin() + 20);
    auto nowRx = std::chrono::steady_clock::now();
    bool newStream = (!rxActive_) || (stream != activeRxStream_);
    if (newStream) {
        if (rxActive_) {
            tlv_.sendEndTx();
            log_.info("bridged inbound DMRD end tx stream-change duration=" + secondsText(nowRx - rxStreamStart_));
        }
        tlv_.sendBeginTx(srcId, dstId, slot, privateCall);
        activeRxStream_ = stream;
        rxStreamStart_ = nowRx;
        lastRxPacket_ = nowRx;
        rxActive_ = true;
        log_.info("bridged inbound DMRD begin tx");
    } else {
        lastRxPacket_ = nowRx;
    }
    auto ambe = extractAmbe72FromDmrd(packet);
    if (!ambe.empty()) {
        tlv_.sendAmbe72(ambe);
    }
    if (frameType == 1 && dtypeVseq == 2) {
        tlv_.sendEndTx();
        log_.info("bridged inbound DMRD end tx duration=" + secondsText(nowRx - rxStreamStart_));
        rxActive_ = false;
        activeRxStream_.clear();
    }
}

std::vector<std::uint8_t> TGIFClient::extractAmbe72FromDmrd(const std::vector<std::uint8_t>& packet) {
    if (packet.size() < 53) return {};
    const std::size_t payloadOffset = 20;
    const std::size_t totalBits = (packet.size() - payloadOffset) * 8;
    if (totalBits < 264) return {};
    std::vector<std::uint8_t> out(27, 0);
    auto getBit = [&](std::size_t idx) -> std::uint8_t {
        std::size_t byteIndex = payloadOffset + (idx / 8);
        std::size_t bitInByte = 7 - (idx % 8);
        return static_cast<std::uint8_t>((packet[byteIndex] >> bitInByte) & 0x01);
    };
    auto setBit = [&](std::size_t idx, std::uint8_t bit) {
        std::size_t byteIndex = idx / 8;
        std::size_t bitInByte = 7 - (idx % 8);
        out[byteIndex] = static_cast<std::uint8_t>(out[byteIndex] | (bit << bitInByte));
    };
    std::size_t outIdx = 0;
    for (std::size_t i = 0; i < 108; ++i) setBit(outIdx++, getBit(i));
    for (std::size_t i = 156; i < 264; ++i) setBit(outIdx++, getBit(i));
    return out;
}

void TGIFClient::processOutgoingTlv(const TLVPacket& packet) {
    if (packet.tag == TAG_TG_TUNE) {
        log_.info("observed TLV tune request -> " + std::string(packet.payload.begin(), packet.payload.end()));
        return;
    }
    if (packet.tag == TAG_REMOTE_CMD) {
        log_.info("observed TLV remote cmd -> " + std::string(packet.payload.begin(), packet.payload.end()));
        return;
    }
    if (packet.tag == TAG_BEGIN_TX) {
        tx_.active = true;
        tx_.srcId = read24(packet.payload, 0);
        auto cmdTg = tlv_.currentTalkgroup();
        tx_.dstId = cmdTg.empty() ? static_cast<std::uint32_t>(std::stoul(startupTg_)) : static_cast<std::uint32_t>(std::stoul(cmdTg));
        if (packet.payload.size() >= 11) tx_.slot2 = (packet.payload[10] >= 2);
        if (packet.payload.size() >= 12) tx_.privateCall = (packet.payload[11] & 0x80) != 0;
        tx_.streamId = randomStreamId();
        tx_.seq = 0;
        tx_.burst = 0;
        log_.info(
            "TX fields src=" + std::to_string(tx_.srcId ? tx_.srcId : radioId_) +
            " dst=" + std::to_string(tx_.dstId) +
            " peer=" + std::to_string(radioId_) +
            " slot2=" + std::string(tx_.slot2 ? "true" : "false") +
            " private=" + std::string(tx_.privateCall ? "true" : "false")
        );
        sendRaw(TgifCodec::encodeDmrdHeader(tx_.seq++, tx_.srcId ? tx_.srcId : radioId_, tx_.dstId, radioId_, tx_.slot2, tx_.privateCall, tx_.streamId), "dmrd-header");
        log_.info("TX begin bridged to DMRD stream=" + std::to_string(tx_.streamId));
        return;
    }
    if (packet.tag == TAG_AMBE_72) {
        if (!tx_.active || packet.payload.size() < 28) return;
        std::vector<std::uint8_t> ambe(packet.payload.begin() + 1, packet.payload.begin() + 28);
        sendRaw(TgifCodec::encodeDmrdVoice(tx_.seq++, tx_.srcId ? tx_.srcId : radioId_, tx_.dstId, radioId_, tx_.slot2, tx_.privateCall, tx_.streamId, tx_.burst, ambe), "dmrd-voice");
        tx_.burst = (tx_.burst + 1) % 6;
        return;
    }
    if (packet.tag == TAG_END_TX) {
        if (!tx_.active) return;
        sendRaw(TgifCodec::encodeDmrdTerminator(tx_.seq++, tx_.srcId ? tx_.srcId : radioId_, tx_.dstId, radioId_, tx_.slot2, tx_.privateCall, tx_.streamId), "dmrd-end");
        tx_ = TxState{};
        log_.info("TX end bridged to DMRD terminator");
        return;
    }
}

bool TGIFClient::openSocket() {
    std::string error;
    if (!socket_.openConnected(host_, port_, recvTimeoutMs_, localBindPort_, error)) {
        log_.error(error);
        return false;
    }
    phase_ = Phase::Opened;
    auto now = std::chrono::steady_clock::now();
    lastPing_ = lastPong_ = lastServerActivity_ = now;
    sentPings_ = ackedPings_ = 0;
    pingOutstanding_ = false;
    refreshInFlight_ = false;
    softRefreshCooldownUntil_ = now + std::chrono::seconds(keepaliveSeconds_ * 2);
    log_.info("socket opened to " + host_ + ":" + std::to_string(port_));
    return true;
}

bool TGIFClient::sendLogin(const std::string& why) {
    std::string error;
    auto pkt = TgifCodec::encodeLogin(radioId_);
    if (!socket_.sendPacket(pkt, error)) {
        log_.error(error);
        return false;
    }
    phase_ = Phase::LoginRequested;
    log_.info(why == "login" ? "login sent" : ("login sent (" + why + ")"));
    return true;
}

bool TGIFClient::sendAuthorization(const std::vector<std::uint8_t>& salt) {
    std::string error;
    auto pkt = TgifCodec::encodeAuthorization(radioId_, securityKey_, salt);
    if (!socket_.sendPacket(pkt, error)) {
        log_.error(error);
        return false;
    }
    phase_ = Phase::AuthRequested;
    log_.info("authorization sent");
    return true;
}

bool TGIFClient::sendConfig(const std::string& why) {
    std::string error;
    auto pkt = TgifCodec::encodeConfig(
        radioId_,
        config_.get("identity", "callsign", ""),
        config_.get("mmdvm", "rx_frequency", "0"),
        config_.get("mmdvm", "tx_frequency", "0"),
        config_.get("mmdvm", "power", "0"),
        config_.get("mmdvm", "color_code", "1"),
        config_.get("mmdvm", "latitude", "0"),
        config_.get("mmdvm", "longitude", "0"),
        config_.get("mmdvm", "height", "0"),
        config_.get("mmdvm", "location", ""),
        config_.get("mmdvm", "description", "TGIFD"),
        config_.get("mmdvm", "slots", "4"),
        config_.get("mmdvm", "url", ""),
        config_.get("mmdvm", "version", "alltune2-tgifd"),
        config_.get("mmdvm", "software", "TGIFD")
    );
    if (!socket_.sendPacket(pkt, error)) {
        log_.error(error);
        return false;
    }
    phase_ = Phase::ConfigRequested;
    log_.info(why == "config" ? "config sent" : ("config sent (" + why + ")"));
    return true;
}

bool TGIFClient::sendOptions(const std::string& why) {
    std::string error;
    auto pkt = TgifCodec::encodeOptions(radioId_, options_);
    if (!socket_.sendPacket(pkt, error)) {
        log_.error(error);
        return false;
    }
    phase_ = Phase::OptionsRequested;
    log_.info(why.empty() ? "options sent" : ("options sent (" + why + ")"));
    return true;
}

bool TGIFClient::sendPing() {
    std::string error;
    auto pkt = TgifCodec::encodePing(radioId_);
    if (!socket_.sendPacket(pkt, error)) {
        log_.error(error);
        return false;
    }
    lastPing_ = std::chrono::steady_clock::now();
    ++sentPings_;
    pingOutstanding_ = true;
    return true;
}

bool TGIFClient::sendClose() {
    std::string error;
    auto pkt = TgifCodec::encodeClose(radioId_);
    if (!socket_.sendPacket(pkt, error)) {
        log_.warn("close send failed: " + error);
        return false;
    }
    log_.info("close sent");
    return true;
}

bool TGIFClient::startSoftRefresh(const std::string& why) {
    auto now = std::chrono::steady_clock::now();
    if (refreshInFlight_) return true;
    if (now < softRefreshCooldownUntil_) return true;
    refreshInFlight_ = true;
    sentPings_ = 0;
    ackedPings_ = 0;
    pingOutstanding_ = false;
    lastPing_ = lastPong_ = lastServerActivity_ = now;
    softRefreshCooldownUntil_ = now + std::chrono::seconds(keepaliveSeconds_ * 2);
    log_.warn("starting soft refresh (" + why + ")");
    return sendLogin("soft-refresh");
}

bool TGIFClient::hardReconnect() {
    log_.warn("starting hard reconnect");
    sendClose();
    socket_.close();
    std::this_thread::sleep_for(std::chrono::seconds(reconnectDelaySeconds_));
    phase_ = Phase::Closed;
    if (!openSocket()) return false;
    return sendLogin();
}

void TGIFClient::onConnected(const std::string& why) {
    phase_ = Phase::Connected;
    connectedOnce_ = true;
    refreshInFlight_ = false;
    pingOutstanding_ = false;
    sentPings_ = 0;
    ackedPings_ = 0;
    auto now = std::chrono::steady_clock::now();
    lastPing_ = lastPong_ = lastServerActivity_ = now;
    softRefreshCooldownUntil_ = now + std::chrono::seconds(keepaliveSeconds_ * 2);
    tlv_.sendTalkgroupTune(effectiveTalkgroup(), why);
    backend_.ensureAttached();
    log_.info("connection to TGIF completed with options");
}

void TGIFClient::networkLoop() {
    if (!backend_.ensureAttached()) {
        log_.warn("private node attach failed or unavailable");
    }
    if (!openSocket()) return;
    if (!sendLogin()) return;

    while (!stop_) {
        std::string error;
        auto packet = socket_.receivePacket(error);
        if (!packet) {
            if (!error.empty()) log_.warn(error);
            break;
        }
        if (!packet->empty()) {
            auto nowEvent = std::chrono::steady_clock::now();
            lastServerActivity_ = nowEvent;
            auto event = TgifCodec::parse(*packet);
            if (event.type == "rptack") {
                if (phase_ == Phase::LoginRequested) {
                    if (!sendAuthorization(event.salt)) break;
                } else if (phase_ == Phase::AuthRequested) {
                    if (!sendConfig(refreshInFlight_ ? "soft-refresh" : "config")) break;
                } else if (phase_ == Phase::ConfigRequested) {
                    if (!sendOptions(refreshInFlight_ ? "soft-refresh" : "config")) break;
                } else if (phase_ == Phase::OptionsRequested) {
                    onConnected(refreshInFlight_ ? "connected-soft-refresh" : "connected");
                }
            } else if (event.type == "mstpong") {
                auto nowPong = std::chrono::steady_clock::now();
                lastPong_ = lastServerActivity_ = nowPong;
                ackedPings_++;
                pingOutstanding_ = false;
                refreshInFlight_ = false;
                softRefreshCooldownUntil_ = nowPong + std::chrono::seconds(keepaliveSeconds_ * 2);
            } else if (event.type == "mstnak") {
                phase_ = Phase::LoginFailed;
                log_.error("TGIF login/auth rejected");
                break;
            } else if (event.type == "mstcl") {
                if (!hardReconnect()) break;
                continue;
            } else if (event.type == "dmrd") {
                lastPong_ = lastServerActivity_ = nowEvent;
                if (sentPings_ > ackedPings_) ackedPings_ = sentPings_;
                pingOutstanding_ = false;
                refreshInFlight_ = false;
                softRefreshCooldownUntil_ = nowEvent + std::chrono::seconds(keepaliveSeconds_ * 6);
                handleDmrd(event.raw);
            }
        }

        TLVPacket outgoing{};
        while (tlv_.popOutgoing(outgoing, 0)) {
            processOutgoingTlv(outgoing);
        }

        auto now = std::chrono::steady_clock::now();

        if (rxActive_) {
            auto rxIdleMs = std::chrono::duration_cast<std::chrono::milliseconds>(now - lastRxPacket_).count();
            if (rxIdleMs >= rxIdleEndMs_) {
                tlv_.sendEndTx();
                log_.info("bridged inbound DMRD end tx timeout duration=" + secondsText(now - rxStreamStart_));
                rxActive_ = false;
                activeRxStream_.clear();
            }
        }

        auto sincePing = std::chrono::duration_cast<std::chrono::seconds>(now - lastPing_).count();
        if (phase_ == Phase::Connected && sincePing >= keepaliveSeconds_) {
            if (!sendPing()) break;
        }

        int missed = sentPings_ - ackedPings_;
        auto sinceServerActivity = std::chrono::duration_cast<std::chrono::seconds>(now - lastServerActivity_).count();
        bool serverQuietLongEnough = sinceServerActivity >= (keepaliveSeconds_ * 3);
        bool refreshAllowed = now >= softRefreshCooldownUntil_;

        if (phase_ == Phase::Connected && serverQuietLongEnough && missed >= softRefreshTriggerMissed_ && !refreshInFlight_) {
            if (!refreshAllowed) {
                if (!pingOutstanding_ && sincePing >= keepaliveSeconds_) {
                    if (!sendPing()) break;
                }
            } else {
                if (!startSoftRefresh("missed-ping-threshold")) break;
            }
        }

        if (serverQuietLongEnough && refreshAllowed && missed >= maxMissed_) {
            if (!hardReconnect()) break;
            continue;
        }

        std::this_thread::sleep_for(std::chrono::milliseconds(20));
    }
    socket_.close();
    backend_.detach();
}
