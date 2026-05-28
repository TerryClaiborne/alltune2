#include "TLVBridge.hpp"

#include <chrono>

namespace {
constexpr std::uint8_t TAG_BEGIN_TX   = 0x00;
constexpr std::uint8_t TAG_END_TX     = 0x02;
constexpr std::uint8_t TAG_TG_TUNE    = 0x03;
constexpr std::uint8_t TAG_REMOTE_CMD = 0x05;
constexpr std::uint8_t TAG_AMBE_72    = 0x07;
constexpr std::uint8_t TAG_SET_INFO   = 0x08;

void writeInt24(std::uint32_t value, std::uint8_t* out) {
    out[0] = static_cast<std::uint8_t>((value >> 16) & 0xFF);
    out[1] = static_cast<std::uint8_t>((value >> 8) & 0xFF);
    out[2] = static_cast<std::uint8_t>(value & 0xFF);
}
void writeInt32(std::uint32_t value, std::uint8_t* out) {
    out[0] = static_cast<std::uint8_t>((value >> 24) & 0xFF);
    out[1] = static_cast<std::uint8_t>((value >> 16) & 0xFF);
    out[2] = static_cast<std::uint8_t>((value >> 8) & 0xFF);
    out[3] = static_cast<std::uint8_t>(value & 0xFF);
}
}

TLVBridge::TLVBridge(const Config& config, Logger& log)
    : config_(config), log_(log) {
    repeaterId_ = static_cast<std::uint32_t>(config_.getInt("identity", "hotspot_radio_id",
        config_.getInt("identity", "dmr_id", 0)));
    tlvRxPort_ = config_.getInt("tlv", "rx_port", 0);
    tlvTimeoutMs_ = config_.getInt("tlv", "timeout_ms", 1000);
    tlvTxHost_ = config_.get("tlv", "tx_host", "127.0.0.1");
    tlvTxPort_ = config_.getInt("tlv", "tx_port", 0);
}

TLVBridge::~TLVBridge() { stop(); }

bool TLVBridge::start() {
    if (tlvRxPort_ <= 0) {
        log_.warn("TLV rx_port not configured; TLV bridge disabled");
        return true;
    }
    std::string error;
    if (!tlvRxSocket_.openBound(tlvRxPort_, tlvTimeoutMs_, error)) {
        log_.error(error);
        return false;
    }
    if (tlvTxPort_ > 0) {
        if (!tlvTxSocket_.openConnected(tlvTxHost_, tlvTxPort_, tlvTimeoutMs_, 0, error)) {
            log_.warn("TLV tx target not available yet: " + error);
        } else {
            txConfigured_ = true;
            log_.info("TLV bridge transmit target set to " + tlvTxHost_ + ":" + std::to_string(tlvTxPort_));
        }
    }
    stop_ = false;
    serviceThread_ = std::thread(&TLVBridge::serviceTlvConnection, this);
    log_.info("TLV bridge listening on UDP port " + std::to_string(tlvRxPort_));
    return true;
}

void TLVBridge::stop() {
    stop_ = true;
    queueCv_.notify_all();
    tlvRxSocket_.close();
    tlvTxSocket_.close();
    if (serviceThread_.joinable()) serviceThread_.join();
}

bool TLVBridge::popOutgoing(TLVPacket& packet, int timeoutMs) {
    std::unique_lock<std::mutex> lock(queueMutex_);
    if (!queueCv_.wait_for(lock, std::chrono::milliseconds(timeoutMs), [&]{ return stop_ || !outboundQueue_.empty(); })) {
        return false;
    }
    if (outboundQueue_.empty()) return false;
    packet = outboundQueue_.front();
    outboundQueue_.pop();
    return true;
}

bool TLVBridge::ensureTxTarget(const std::string& why) {
    if (tlvTxPort_ <= 0) {
        return false;
    }

    if (txConfigured_) {
        return true;
    }

    std::string error;
    if (!tlvTxSocket_.openConnected(tlvTxHost_, tlvTxPort_, tlvTimeoutMs_, 0, error)) {
        log_.warn("TLV tx target reopen failed (" + why + "): " + error);
        txConfigured_ = false;
        return false;
    }

    txConfigured_ = true;
    log_.info("TLV tx target ready (" + why + ") -> " + tlvTxHost_ + ":" + std::to_string(tlvTxPort_));
    return true;
}

bool TLVBridge::sendPacketToBridge(const TLVPacket& packet, const std::string& why) {
    std::string error;
    auto raw = encode(packet);

    // Always prefer the configured Analog_Bridge TLV target.
    // Do not depend on lastPeer for TGIF inbound audio; lastPeer may not exist
    // until local PTT happens, which caused receive audio to stay asleep.
    if (ensureTxTarget(why)) {
        if (tlvTxSocket_.sendPacket(raw, error)) {
            return true;
        }

        log_.info("TLV tx target not ready yet (" + why + ")");

        // Connected UDP can report ECONNREFUSED after Analog_Bridge restarts.
        // Reopen once immediately and retry before falling back.
        tlvTxSocket_.close();
        txConfigured_ = false;

        if (ensureTxTarget(why + "-retry")) {
            error.clear();
            if (tlvTxSocket_.sendPacket(raw, error)) {
                log_.info("TLV tx target ready after retry (" + why + ")");
                return true;
            }
            log_.warn("TLV configured target retry failed (" + why + "): " + error);
        }
    }

    // Fallback only for legacy/local-peer behavior. Inbound TGIF receive should
    // normally succeed above through the configured target.
    error.clear();
    if (!tlvRxSocket_.sendToLastPeer(raw, error)) {
        log_.warn("TLV send skipped (" + why + "): " + error);
        return false;
    }

    log_.info("TLV sent via last peer fallback (" + why + ")");
    return true;
}

void TLVBridge::pushIncoming(const TLVPacket& packet) {
    sendPacketToBridge(packet, "incoming");
}

void TLVBridge::sendTalkgroupTune(const std::string& tg, const std::string& why) {
    if (tg.empty()) return;
    TLVPacket tune;
    tune.tag = TAG_TG_TUNE;
    tune.payload.assign(tg.begin(), tg.end());
    sendPacketToBridge(tune, why);

    TLVPacket cmd;
    cmd.tag = TAG_REMOTE_CMD;
    std::string text = "txTg=" + tg;
    cmd.payload.assign(text.begin(), text.end());
    sendPacketToBridge(cmd, why);

    {
        std::lock_guard<std::mutex> lock(stateMutex_);
        currentTg_ = tg;
    }
    log_.info("TLV tune sent -> " + tg + " (" + why + ")");
}

void TLVBridge::sendRemoteCommand(const std::string& cmdText, const std::string& why) {
    if (cmdText.empty()) return;
    TLVPacket pkt;
    pkt.tag = TAG_REMOTE_CMD;
    pkt.payload.assign(cmdText.begin(), cmdText.end());
    sendPacketToBridge(pkt, why);
    log_.info("TLV remote cmd sent -> " + cmdText + " (" + why + ")");
}

void TLVBridge::sendBeginTx(std::uint32_t srcId, std::uint32_t dstId, int slot, bool privateCall) {
    TLVPacket pkt;
    pkt.tag = TAG_BEGIN_TX;
    pkt.payload.resize(12, 0);
    writeInt24(srcId, pkt.payload.data() + 0);
    // bytes 3..6 reserved for repeater/hotspot id in STFU-style local playback begin-tx
    writeInt32(repeaterId_, pkt.payload.data() + 3);
    writeInt24(dstId, pkt.payload.data() + 7);
    pkt.payload[10] = static_cast<std::uint8_t>(slot <= 1 ? 1 : 2);
    activeRxSlot_ = pkt.payload[10];
    pkt.payload[11] = static_cast<std::uint8_t>((privateCall ? 0x80 : 0x00) | 0x01);
    // bytes 12..19 remain zeroed to keep the same 20-byte shape observed from local TLV begin-tx
    sendPacketToBridge(pkt, "rx-begin");
    log_.info("TLV BEGIN_TX sent src=" + std::to_string(srcId) +
              " rpt=" + std::to_string(repeaterId_) + " dst=" + std::to_string(dstId) +
              " slot=" + std::to_string(pkt.payload[10]) +
              " flags=" + std::to_string(static_cast<int>(pkt.payload[11])));
}

void TLVBridge::sendAmbe72(const std::vector<std::uint8_t>& ambe27) {
    if (ambe27.size() != 27) return;
    TLVPacket pkt;
    pkt.tag = TAG_AMBE_72;
    pkt.payload.resize(28, 0);
    pkt.payload[0] = static_cast<std::uint8_t>(activeRxSlot_ <= 1 ? 1 : 2);
    std::copy(ambe27.begin(), ambe27.end(), pkt.payload.begin() + 1);
    sendPacketToBridge(pkt, "rx-ambe");
}

void TLVBridge::sendEndTx() {
    TLVPacket pkt;
    pkt.tag = TAG_END_TX;
    pkt.payload = {static_cast<std::uint8_t>(activeRxSlot_ <= 1 ? 1 : 2)};
    sendPacketToBridge(pkt, "rx-end");
    log_.info("TLV END_TX sent");
}

std::string TLVBridge::currentTalkgroup() const {
    std::lock_guard<std::mutex> lock(stateMutex_);
    return currentTg_;
}

void TLVBridge::serviceTlvConnection() {
    while (!stop_) {
        std::string error;
        auto raw = tlvRxSocket_.receivePacket(error);
        if (!raw) {
            if (!error.empty()) log_.warn(error);
            break;
        }
        if (raw->empty()) continue;
        auto packet = parse(*raw);
        if (!packet) continue;
        handlePacket(*packet);
        {
            std::lock_guard<std::mutex> lock(queueMutex_);
            outboundQueue_.push(*packet);
        }
        queueCv_.notify_one();
    }
}

void TLVBridge::processTlvQueue() {
    while (!stop_) {
        std::this_thread::sleep_for(std::chrono::milliseconds(250));
    }
}

void TLVBridge::handlePacket(const TLVPacket& packet) {
    if (packet.tag == TAG_TG_TUNE) {
        std::string tg(packet.payload.begin(), packet.payload.end());
        {
            std::lock_guard<std::mutex> lock(stateMutex_);
            currentTg_ = tg;
        }
        log_.info("TLV TAG_TG_TUNE -> " + tg);
    } else if (packet.tag == TAG_REMOTE_CMD) {
        std::string cmd(packet.payload.begin(), packet.payload.end());
        log_.info("TLV TAG_REMOTE_CMD -> " + cmd);
        if (cmd.rfind("txTg=", 0) == 0) {
            std::string tg = cmd.substr(5);
            std::lock_guard<std::mutex> lock(stateMutex_);
            currentTg_ = tg;
        }
    } else if (packet.tag == TAG_AMBE_72) {
    } else if (packet.tag == TAG_BEGIN_TX) {
        log_.info("TLV TAG_BEGIN_TX len=" + std::to_string(packet.payload.size()));
    } else if (packet.tag == TAG_END_TX) {
        log_.info("TLV TAG_END_TX len=" + std::to_string(packet.payload.size()));
    } else if (packet.tag == TAG_SET_INFO) {
        log_.info("TLV TAG_SET_INFO len=" + std::to_string(packet.payload.size()));
    } else {
        log_.info("TLV tag=" + std::to_string(packet.tag) + " len=" + std::to_string(packet.payload.size()));
    }
}

std::optional<TLVPacket> TLVBridge::parse(const std::vector<std::uint8_t>& raw) {
    if (raw.size() < 2) return std::nullopt;
    TLVPacket pkt;
    pkt.tag = raw[0];
    auto len = raw[1];
    if (raw.size() < static_cast<size_t>(2 + len)) return std::nullopt;
    pkt.payload.assign(raw.begin() + 2, raw.begin() + 2 + len);
    return pkt;
}

std::vector<std::uint8_t> TLVBridge::encode(const TLVPacket& packet) {
    std::vector<std::uint8_t> out;
    out.push_back(packet.tag);
    out.push_back(static_cast<std::uint8_t>(packet.payload.size()));
    out.insert(out.end(), packet.payload.begin(), packet.payload.end());
    return out;
}
