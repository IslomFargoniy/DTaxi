#!/usr/bin/env python3
"""
O'zbek kirill satrlari (values-b+uz+Cyrl) lotin satrlaridan (values) yasaladi — qo'lda yozilmaydi.
Qoidalar serverdagi app/Support/UzbekCyrillic.php bilan bir xil.

values/strings.xml o'zgarsa qayta ishga tushiring:
    python3 android/tools/uz_cyrillic.py
"""
import os
import re
import sys

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'app', 'src', 'main', 'res')
SOURCE = os.path.join(ROOT, 'values', 'strings.xml')
TARGET = os.path.join(ROOT, 'values-b+uz+Cyrl', 'strings.xml')

KEEP = {'Dtaxi', 'Click', 'Payme', 'SpeechKit', 'Firebase', 'Telegram', 'Android', 'Asterisk', 'Xiaomi', 'Tecno',
        'Infinix', 'Yandex', 'Google', 'Mac', 'IP', 'ID', 'GPS', 'SIP', 'SMS', 'APK', 'URL', 'Wi-Fi'}

LETTERS = dict(zip('abdfghijklmnopqrstuvxyze', 'абдфгҳижклмнопқрстувхйзе'))
VOWELS = 'aeiou'


def is_kept(bare: str) -> bool:
    return bare in KEEP or bool(re.search(r'[cC](?![hH])|[wW]', bare)) or (len(bare) > 1 and bare.isupper() and len(bare) <= 5)


def word(w: str) -> str:
    # Brend/qisqartma + qo'shimcha (Payme'da, ID'ni): asos lotinda, qo'shimcha kirillda
    m = re.match(r"^([A-Za-z]+)'([a-z]+)$", w)
    if m and is_kept(m.group(1)):
        return m.group(1) + "'" + word(m.group(2))
    bare = w.rstrip("'")
    if is_kept(bare):
        return w
    upper_all = len(bare) > 1 and bare.isupper()
    upper_first = w[0].isupper()
    low = w.lower()
    out = ''
    i = 0
    n = len(low)
    while i < n:
        c = low[i]
        nxt = low[i + 1] if i + 1 < n else ''
        prev = low[i - 1] if i > 0 else ''
        if c in 'og' and nxt == "'":
            out += 'ў' if c == 'o' else 'ғ'
            i += 2
            continue
        if c == 's' and nxt == 'h':
            out += 'ш'; i += 2; continue
        if c == 'c' and nxt == 'h':
            out += 'ч'; i += 2; continue
        if c == 'y' and nxt in 'aoue' and nxt and not (nxt == 'o' and i + 2 < n and low[i + 2] == "'"):
            out += {'a': 'я', 'o': 'ё', 'u': 'ю', 'e': 'е'}[nxt]
            i += 2
            continue
        if c == 'e' and (i == 0 or prev in VOWELS):
            out += 'э'; i += 1; continue
        if c == "'":
            # Tutuq belgisi (ma'lumot) — ъ; so'z oxirida o'zgarmaydi
            out += "'" if i == n - 1 else 'ъ'
            i += 1
            continue
        out += LETTERS.get(c, c)
        i += 1
    if upper_all:
        return out.upper()
    return out[:1].upper() + out[1:] if upper_first else out


def convert(text: str) -> str:
    # XML'dagi \' — oddiy apostrof; %1$s, %d, \" va \n o'zgarmaydi
    text = text.replace("\\'", "'")
    parts = re.split(r'(%\d*\$?[sd]|\\[n"t]|&[a-z]+;)', text)
    out = []
    for idx, part in enumerate(parts):
        if idx % 2 == 1:
            out.append(part)
        else:
            out.append(re.sub(r"[A-Za-z][A-Za-z']*", lambda m: word(m.group(0)), part))
    return ''.join(out).replace("'", "\\'")


def main() -> int:
    source = open(SOURCE, encoding='utf-8').read()
    result = re.sub(r'(<string name="[^"]+">)(.*?)(</string>)', lambda m: m.group(1) + convert(m.group(2)) + m.group(3), source, flags=re.S)
    result = result.replace('<resources>', '<!-- values/strings.xml dan yasalgan: python3 android/tools/uz_cyrillic.py -->\n<resources>', 1)
    os.makedirs(os.path.dirname(TARGET), exist_ok=True)
    open(TARGET, 'w', encoding='utf-8').write(result)
    print(f'Tayyor: {TARGET}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
