Drop these files into /var/www/html/alltune2/tgif-hblink

Files:
- hblink.cfg
- rules.py.template
- set_hblink_tg.sh
- MMDVM_Bridge.hblink.ini
- alltune2-hblink-audio-helper.sh

Before start:
1. Put your real TGIF key into hblink.cfg (REPEATER-1 PASSPHRASE)
2. Run: ./set_hblink_tg.sh 9990 /var/www/html/alltune2/tgif-hblink
3. Use bridge.py, not hblink.py, for this audio test path.
