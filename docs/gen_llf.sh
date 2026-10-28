#!/usr/bin/env bash
# Генерация docs/LLF.md — индекса функций для LLM-ассистентов.
# Запуск из корня репозитория:  bash docs/gen_llf.sh
set -euo pipefail
cd "$(dirname "$0")/.."

python3 - > docs/LLF.md <<'PYEOF'
import re, os

print("""# LLF.md — List of Low-Level Functions (индекс для LLM)

> Автогенерируемый индекс: имя функции → файл:строка → назначение.
> Обновлять после правок: `bash docs/gen_llf.sh`
> Не читать этот файл целиком при задаче с одной функцией — делать grep по нему:
> `grep -n "имя_функции" docs/LLF.md` → затем читать код по указанному file:line.
""")

HEADER_UNUSED = """

> Автогенерируемый индекс: имя функции → файл:строка → назначение.
> Обновлять после правок: `bash docs/gen_llf.sh`
> Не читать этот файл целиком при задаче с одной функцией — делать grep по нему:
> `grep -n "имя_функции" docs/LLF.md` → затем читать код по указанному file:line.
"""

def doc_line(src, i):
    """Ближайшая содержательная строка doc-комментария над определением на строке i."""
    j = i - 1
    best = ''
    while j >= 0:
        s = src[j].strip()
        if s == '' and not best:
            j -= 1; continue
        if s.startswith('*/') or s.startswith('*'):
            cand = re.sub(r'^\*+\s*', '', s).strip()
            if cand and not cand.startswith('@') and not cand.startswith('/'):
                best = cand
                j -= 1
                continue
            j -= 1; continue
        if s.startswith('//'):
            best = best or s.lstrip('/ ').strip()
            break
        break
    return best[:120]

def scan(path):
    src = open(path, encoding='utf-8').read().splitlines()
    out = []
    php = path.endswith('.php')
    for i, line in enumerate(src):
        if php:
            m = re.match(r'\s*function\s+([A-Za-z0-9_]+)\s*\(', line)
        else:
            m = (re.match(r'\s*(?:async\s+)?function\s+([A-Za-z0-9_$]+)', line) or
                 re.match(r'\s*(?:const|let|var)\s+([A-Za-z0-9_$]+)\s*=\s*(?:async\s*)?(?:function|\()', line))
        if m:
            name = m.group(1)
            # пропускаем вложенные стрелочные/анонимные обёртки вида `const duration = ...`
            if not php and '=' in line.split(m.group(1))[1][:20] and 'function' not in line and '(' not in line.split('=',1)[-1][:15]:
                continue
            out.append((name, i + 1, doc_line(src, i)))
    return out

def section(title, files):
    print(f"\n## {title}\n")
    n = 0
    for f in files:
        funcs = scan(f)
        if not funcs:
            continue
        print(f"### `{f}` ({len(funcs)} фукн.)\n")
        print("| Функция | Строка | Назначение |")
        print("|---|---|---|")
        for name, ln, d in funcs:
            print(f"| `{name}` | :{ln} | {d} |")
        n += len(funcs)
        print()
    return n

total = 0
js_files = sorted('js/' + f for f in os.listdir('js') if f.endswith('.js'))
if js_files:
    total += section(f"Клиент ({', '.join(js_files)})", js_files)
http = sorted('php/' + f for f in os.listdir('php') if f.endswith('.php'))
total += section("HTTP-слой (php/*.php)", http)
total += section("Ядро движка (php/engine/)", ['php/engine/' + f for f in sorted(os.listdir('php/engine'))])
total += section("Системы механик (php/systems/)", ['php/systems/' + f for f in sorted(os.listdir('php/systems'))])
if os.path.isdir('tests'):
    total += section("Тесты (tests/)", ['tests/' + f for f in sorted(os.listdir('tests')) if f.endswith('.php')])
print(f"\n<!-- Всего функций: {total} -->")
PYEOF

echo "docs/LLF.md обновлён: $(wc -l < docs/LLF.md) строк"
