#!/usr/bin/env python3
"""
Faqat lokal ishlab chiqish: haqiqiy telefon (Mac bilan bitta Wi-Fi) Multipass VM'ga ulanishi uchun
Mac'ning 80/443 portlarini (TCP) va qo'ng'iroq ovozi portlarini (UDP, RTP) VM'ga yo'naltiradi.
VM manzili 192.168.252.x telefonga ko'rinmaydi.

Ovoz: Asterisk telefonga ICE nomzodi sifatida o'z ichki manzilini beradi — telefon unga yetolmaydi.
Shuning uchun skript ishga tushganda VM'dagi Asterisk'ga Mac'ning Wi-Fi manzilini beradi
(rtp.conf → ice_host_candidates) va RTP portlarini shu yerdan VM'ga uzatadi.
Wi-Fi almashsa — skriptni qayta ishga tushiring.

    python3 deploy/dev-lan-proxy.py            # VM: 192.168.252.2
    VM_IP=192.168.252.3 python3 deploy/dev-lan-proxy.py
"""
import asyncio
import os
import socket
import subprocess

VM_IP = os.environ.get("VM_IP", "192.168.252.2")
VM_NAME = os.environ.get("VM_NAME", "ibtaxi")
PORTS = [80, 443]
# Lokal sinovda bir nechta qo'ng'iroq yetadi — 100 ta port (har qo'ng'iroqqa 2 ta)
RTP_START = int(os.environ.get("RTP_START", "10000"))
RTP_END = int(os.environ.get("RTP_END", "10100"))


async def pipe(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    try:
        while data := await reader.read(65536):
            writer.write(data)
            await writer.drain()
    except (ConnectionError, asyncio.CancelledError):
        pass
    finally:
        writer.close()


def handler(port: int):
    async def handle(client_reader, client_writer):
        # Kim ulanayotganini ko'rish — telefon so'rovi Mac'ga yetib kelyaptimi
        peer = client_writer.get_extra_info("peername")
        print(f"ulanish: {peer[0] if peer else '?'} → :{port}", flush=True)
        try:
            vm_reader, vm_writer = await asyncio.open_connection(VM_IP, port)
        except OSError:
            client_writer.close()
            return
        await asyncio.gather(pipe(client_reader, vm_writer), pipe(vm_reader, client_writer))

    return handle


class UdpRelay(asyncio.DatagramProtocol):
    """
    Mac:port ← telefon → VM:port. Har telefon manzili uchun alohida VM tomondagi soket: VM javobi
    o'sha telefonga xuddi shu Mac:port dan qaytadi (WebRTC ICE simmetrik yo'lni kutadi).
    """

    def __init__(self, port: int) -> None:
        self.port = port
        self.transport: asyncio.DatagramTransport | None = None
        self.upstreams: dict[tuple, asyncio.DatagramTransport] = {}

    def connection_made(self, transport) -> None:
        self.transport = transport

    def datagram_received(self, data: bytes, client: tuple) -> None:
        upstream = self.upstreams.get(client)
        if upstream is not None:
            upstream.sendto(data)
            return
        asyncio.ensure_future(self._open(client, data))

    async def _open(self, client: tuple, first: bytes) -> None:
        relay = self

        class Back(asyncio.DatagramProtocol):
            def datagram_received(self, data: bytes, _addr) -> None:
                if relay.transport is not None:
                    relay.transport.sendto(data, client)

        loop = asyncio.get_running_loop()
        upstream, _ = await loop.create_datagram_endpoint(Back, remote_addr=(VM_IP, self.port))
        self.upstreams[client] = upstream
        print(f"ovoz: {client[0]} → :{self.port}", flush=True)
        upstream.sendto(first)


def configure_asterisk(lan_ip: str) -> None:
    """VM'dagi Asterisk ICE nomzodida ichki manzil o'rniga Mac'ning Wi-Fi manzilini e'lon qiladi."""
    conf = (
        "[general]\n"
        f"rtpstart={RTP_START}\n"
        f"rtpend={RTP_END}\n"
        "icesupport=yes\n"
        "\n"
        "; dev-lan-proxy.py yozdi: telefon Mac orqali ovoz yuboradi\n"
        "[ice_host_candidates]\n"
        f"{VM_IP} => {lan_ip},include_local_address\n"
    )
    script = f"cat > /etc/asterisk/rtp.conf <<'EOF'\n{conf}EOF\nasterisk -rx 'module reload res_rtp_asterisk.so'"
    result = subprocess.run(["multipass", "exec", VM_NAME, "--", "sudo", "bash", "-c", script], capture_output=True, text=True)
    if result.returncode == 0:
        print(f"Asterisk: ovoz nomzodi {VM_IP} → {lan_ip} (RTP {RTP_START}–{RTP_END})", flush=True)
    else:
        print(f"Asterisk sozlanmadi (ovoz telefonga kelmasligi mumkin): {result.stderr.strip()}", flush=True)


async def main() -> None:
    servers = [await asyncio.start_server(handler(p), "0.0.0.0", p) for p in PORTS]
    loop = asyncio.get_running_loop()
    for port in range(RTP_START, RTP_END + 1):
        await loop.create_datagram_endpoint(lambda p=port: UdpRelay(p), local_addr=("0.0.0.0", port))

    # Mac'ning joriy Wi-Fi manzili — telefondagi ilovaning login ekranida "Server manzili"ga yoziladi
    probe = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        probe.connect(("8.8.8.8", 80))
        lan_ip = probe.getsockname()[0]
    except OSError:
        lan_ip = "?"
    finally:
        probe.close()
    print(f"Yo'naltirish: Mac :{PORTS} (TCP) va :{RTP_START}-{RTP_END} (UDP) → {VM_IP}", flush=True)
    print(f"Telefonda server manzili: {lan_ip}  (panel: https://{lan_ip})", flush=True)
    if lan_ip != "?":
        configure_asterisk(lan_ip)
    await asyncio.gather(*(s.serve_forever() for s in servers))


if __name__ == "__main__":
    asyncio.run(main())
