import re, sys, os
root = os.path.join(os.path.dirname(__file__), '..')
llf = open(os.path.join(root, 'docs/LLF.md')).read()
sections = {}
cur = None
for l in llf.splitlines():
    m = re.match(r'^### `([\w/.]+)` \((\d+)', l)
    if m:
        cur = m.group(1); sections[cur] = []; continue
    if re.match(r'^## ', l): cur = None
    if cur:
        m2 = re.match(r'^\| `(\w+)` \| :(\d+) \|', l)
        if m2: sections[cur].append((m2.group(1), int(m2.group(2))))
problems = []; total = 0
for path, entries in sections.items():
    full = os.path.join(root, path)
    if not os.path.exists(full):
        problems.append(f"MISSING FILE {path}"); continue
    src = open(full).read().splitlines()
    real = [(m.group(1), i+1) for i, l in enumerate(src)
            for m in [re.match(r'\s*(?:async\s+)?function\s+(\w+)', l)] if m]
    if sorted(n for n,_ in entries) != sorted(n for n,_ in real):
        problems.append(f"{path}: name set mismatch")
    total += len(real)
    rmap = dict(real)
    for name, ln in entries:
        if name in rmap and rmap[name] != ln:
            problems.append(f"{path}: {name} indexed :{ln}, actual :{rmap[name]}")
print(f"files indexed: {len(sections)}, functions: {total}")
if problems:
    print("PROBLEMS:"); [print(" -", p) for p in problems]; sys.exit(1)
print("ALL OK")
