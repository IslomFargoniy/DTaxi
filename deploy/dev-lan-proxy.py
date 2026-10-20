#!/usr/bin/env python3
"""
Faqat lokal ishlab chiqish: haqiqiy telefon (Mac bilan bitta Wi-Fi) Multipass VM'ga ulanishi uchun
Mac'ning 80/443 portlarini VM'ga yo'naltiradi. VM manzili 192.168.252.x telefonga ko'rinmaydi.

    python3 deploy/dev-lan-proxy.py            # VM: 192.168.252.2
    VM_IP=192.168.252.3 python3 deploy/dev-lan-proxy.py
"""
import asyncio
import os
import socket

VM_IP = os.environ.get("VM_IP", "192.168.252.2")
PORTS = [80, 443]


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


async def main() -> None:
    servers = [await asyncio.start_server(handler(p), "0.0.0.0", p) for p in PORTS]
    # Mac'ning joriy Wi-Fi manzili — telefondagi ilovaning login ekranida "Server manzili"ga yoziladi
    probe = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        probe.connect(("8.8.8.8", 80))
        lan_ip = probe.getsockname()[0]
    except OSError:
        lan_ip = "?"
    finally:
        probe.close()
    print(f"Yo'naltirish: Mac :{PORTS} → {VM_IP}", flush=True)
    print(f"Telefonda server manzili: {lan_ip}  (panel: http://{lan_ip})", flush=True)
    await asyncio.gather(*(s.serve_forever() for s in servers))


if __name__ == "__main__":
    asyncio.run(main())
