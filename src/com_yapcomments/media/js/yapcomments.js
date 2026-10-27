/*!
 * YapComments 1.0.1 by drek124 - https://github.com/drek124/
 * Progressive enhancement for the server-rendered comment threads.
 */

const T = (key, fallback = '') => {
  const k = `COM_YAPCOMMENTS_JS_${key}`;
  const v = window.Joomla && Joomla.Text ? Joomla.Text._(k, fallback) : fallback;
  return v && v !== k ? v : fallback;
};

const sprintf = (str, ...args) => {
  let i = 0;
  return str.replace(/%[sd]/g, () => String(args[i++] ?? ''));
};

const parse = (html) => {
  const tpl = document.createElement('template');
  tpl.innerHTML = html.trim();
  return tpl.content;
};

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const FLAG_MARKDOWN = 1;
const FLAG_LINKS = 2;
const FLAG_IMAGES = 4;
const FLAG_CODE = 8;

const esc = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const mark = (s) => `<span class="yap-hl-mark">${esc(s)}</span>`;

/**
 * Enhances a plain textarea with live Markdown highlighting and list continuation.
 *
 * The textarea stays the real input (native undo, IME, autocorrect, accessibility);
 * its text is made transparent and a styled copy is painted exactly behind it.
 * Styles never change glyph advance widths, so the caret always lines up:
 * bold is drawn with a text stroke and italic runs get a measured letter-spacing.
 */
class YapEditor {
  constructor(textarea, flags) {
    this.ta = textarea;
    this.flags = flags;

    // Without Markdown there is nothing to style, but bare links still are.
    this.highlight = (flags & (FLAG_MARKDOWN | FLAG_LINKS)) !== 0;

    textarea.addEventListener('keydown', (e) => this.onKeydown(e));

    if (!this.highlight) return;

    this.wrap = document.createElement('div');
    this.wrap.className = 'yap-hl-wrap';
    this.layer = document.createElement('div');
    this.layer.className = 'yap-hl';
    this.layer.setAttribute('aria-hidden', 'true');

    textarea.parentNode.insertBefore(this.wrap, textarea);
    this.wrap.append(this.layer, textarea);
    textarea.classList.add('yap-hl-input');
    // dir="auto" resolves an empty field to LTR, which strands the placeholder on the
    // wrong side of RTL pages, so it is only applied once there is text (see render).
    this.autoDir = !textarea.hasAttribute('dir');
    if (!this.autoDir) this.layer.setAttribute('dir', textarea.getAttribute('dir'));

    textarea.addEventListener('input', () => this.render());
    textarea.addEventListener('scroll', () => this.syncScroll());

    if ('ResizeObserver' in window) new ResizeObserver(() => this.syncBox()).observe(textarea);
    this.syncBox();
    this.render();
  }

  /** The element to show or hide (the wrapper when highlighting). */
  get box() {
    return this.wrap || this.ta;
  }

  /** Copy every metric that affects text layout from the textarea to the layer. */
  syncBox() {
    if (!this.layer) return;
    const cs = getComputedStyle(this.ta);
    const props = ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'wordSpacing', 'textIndent',
      'textTransform', 'tabSize', 'paddingTop', 'paddingBottom', 'paddingLeft', 'paddingRight',
      'borderTopWidth', 'borderBottomWidth', 'borderLeftWidth', 'borderRightWidth', 'borderRadius', 'textAlign'];
    props.forEach((p) => { this.layer.style[p] = cs[p]; });

    // Reserve the textarea's scrollbar width so lines wrap at the same place.
    const bars = this.ta.offsetWidth - this.ta.clientWidth - parseFloat(cs.borderLeftWidth) - parseFloat(cs.borderRightWidth);
    const side = cs.direction === 'rtl' ? 'paddingLeft' : 'paddingRight';
    this.layer.style[side] = `${parseFloat(cs[side]) + Math.max(0, bars)}px`;
    this.font = `${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
    this.fixItalics();
    this.syncScroll();
  }

  syncScroll() {
    if (this.layer) this.layer.scrollTop = this.ta.scrollTop;
  }

  render() {
    if (!this.layer) return;
    const value = this.ta.value;
    if (this.autoDir && this.ta.hasAttribute('dir') !== (value !== '')) {
      [this.ta, this.layer].forEach((el) => {
        if (value) el.setAttribute('dir', 'auto');
        else el.removeAttribute('dir');
      });
      this.syncBox();
    }
    // A trailing newline needs something after it to occupy the last line.
    this.layer.innerHTML = this.blocks(value) + (value.endsWith('\n') || value === '' ? ' ' : '');
    this.fixItalics();
    this.syncScroll();
  }

  has(flag) {
    return (this.flags & flag) === flag;
  }

  blocks(value) {
    const md = this.has(FLAG_MARKDOWN);
    let fence = false;

    return value.split('\n').map((line) => {
      if (md && this.has(FLAG_CODE) && /^\s{0,3}```/.test(line)) {
        fence = !fence;
        return mark(line);
      }

      if (fence) return `<span class="yap-hl-code">${esc(line)}</span>`;
      if (!md) return this.inline(line);

      let m;

      if ((m = /^(\s{0,3}#{1,6}\s+)(.*)$/.exec(line))) {
        return mark(m[1]) + `<span class="yap-hl-strong yap-hl-heading">${this.inline(m[2])}</span>`;
      }

      if (/^\s{0,3}(?:-{3,}|\*{3,}|_{3,})\s*$/.test(line)) return mark(line);

      if ((m = /^(\s{0,3}>\s?)(.*)$/.exec(line))) {
        return `<span class="yap-hl-bullet">${esc(m[1])}</span><span class="yap-hl-quote">${this.inline(m[2])}</span>`;
      }

      if ((m = /^(\s{0,3})([-*+]|\d{1,9}[.)])(\s+)(.*)$/.exec(line))) {
        return esc(m[1]) + `<span class="yap-hl-bullet">${esc(m[2])}</span>` + esc(m[3]) + this.inline(m[4]);
      }

      return this.inline(line);
    }).join('\n');
  }

  inline(text) {
    if (!this.has(FLAG_MARKDOWN)) return this.has(FLAG_LINKS) ? this.autolinks(text) : esc(text);

    const re = new RegExp([
      '(\\\\[\\\\`*_{}\\[\\]()#+\\-.!|~>])', // 1 backslash escape
      '`([^`\\n]+)`', // 2 inline code
      '(\\*\\*|__)(?=\\S)(.+?)(?<=\\S)\\3', // 3,4 bold
      '~~(?=\\S)(.+?)(?<=\\S)~~', // 5 strike
      '\\|\\|(?=\\S)(.+?)(?<=\\S)\\|\\|', // 6 spoiler
      '(?<![\\w*])\\*(?=[^\\s*])(.+?)(?<=[^\\s*])\\*(?![\\w*])', // 7 italic *
      '(?<!\\w)_(?=\\S)(.+?)(?<=\\S)_(?!\\w)', // 8 italic _
      '(!?)\\[([^\\]\\n]{1,300})\\]\\(([^\\s)]{1,2048})\\)', // 9,10,11 link / image
      '(https?:\\/\\/[^\\s<>"]+)', // 12 bare url
    ].join('|'), 'g');

    let out = '';
    let last = 0;
    let m;

    while ((m = re.exec(text))) {
      out += esc(text.slice(last, m.index));
      last = m.index + m[0].length;

      if (m[1]) out += mark('\\') + esc(m[1].slice(1));
      else if (m[2] !== undefined) {
        out += this.has(FLAG_CODE) ? mark('`') + `<span class="yap-hl-code">${esc(m[2])}</span>` + mark('`') : esc(m[0]);
      } else if (m[3]) out += mark(m[3]) + `<span class="yap-hl-strong">${this.inline(m[4])}</span>` + mark(m[3]);
      else if (m[5] !== undefined) out += mark('~~') + `<span class="yap-hl-del">${this.inline(m[5])}</span>` + mark('~~');
      else if (m[6] !== undefined) out += mark('||') + `<span class="yap-hl-spoiler">${this.inline(m[6])}</span>` + mark('||');
      else if (m[7] !== undefined) out += mark('*') + `<span class="yap-hl-em">${this.inline(m[7])}</span>` + mark('*');
      else if (m[8] !== undefined) out += mark('_') + `<span class="yap-hl-em">${this.inline(m[8])}</span>` + mark('_');
      else if (m[10] !== undefined) {
        const image = m[9] === '!';
        const allowed = image ? this.has(FLAG_IMAGES) || this.has(FLAG_LINKS) : this.has(FLAG_LINKS);
        out += allowed
          ? mark(`${m[9]}[`) + `<span class="yap-hl-link">${image ? esc(m[10]) : this.inline(m[10])}</span>` + mark('](')
            + `<span class="yap-hl-url">${esc(m[11])}</span>` + mark(')')
          : esc(m[0]);
      } else if (m[12]) {
        out += this.autolinks(m[12]);
      }
    }

    return out + esc(text.slice(last));
  }

  autolinks(text) {
    if (!this.has(FLAG_LINKS)) return esc(text);
    return text.replace(/https?:\/\/[^\s<>"]+/g, '\u0000$&\u0001').split(/(\u0000[^\u0001]*\u0001)/).map((part) => {
      if (!part.startsWith('\u0000')) return esc(part);
      const url = part.slice(1, -1);
      const trail = (/[.,;:!?'*)]+$/.exec(url) || [''])[0];
      return `<span class="yap-hl-link">${esc(url.slice(0, url.length - trail.length))}</span>${esc(trail)}`;
    }).join('');
  }

  /** Give each italic run the exact width it would have upright. */
  fixItalics() {
    if (!this.layer) return;
    const runs = this.layer.querySelectorAll('.yap-hl-em');
    if (!runs.length) return;

    this.ctx = this.ctx || document.createElement('canvas').getContext('2d');

    runs.forEach((run) => {
      const text = run.textContent;
      const chars = [...text].length;
      if (!chars) return;
      this.ctx.font = this.font;
      const upright = this.ctx.measureText(text).width;
      this.ctx.font = `italic ${this.font}`;
      const italic = this.ctx.measureText(text).width;
      run.style.letterSpacing = `${(upright - italic) / chars}px`;
    });
  }

  /**
   * Replace a range through the editing pipeline so it lands in the undo history.
   */
  replace(start, end, text, select = 'end') {
    const ta = this.ta;
    ta.focus();
    ta.setSelectionRange(start, end);

    let done = false;
    try {
      done = document.execCommand('insertText', false, text);
    } catch (e) { /* not supported */ }

    if (!done) {
      ta.setRangeText(text, start, end, select === 'select' ? 'select' : 'end');
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    } else if (select === 'select') {
      ta.setSelectionRange(start, start + text.length);
    } else {
      // Chrome can leave the caret before a trailing newline after deleting; pin it.
      ta.setSelectionRange(start + text.length, start + text.length);
    }
  }

  /** Enter continues bullet lists, numbered lists and quotes; Enter on an empty item ends it. */
  onKeydown(e) {
    if (e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey || e.isComposing) return;
    if (!this.has(FLAG_MARKDOWN)) return;

    const ta = this.ta;
    const pos = ta.selectionStart;
    if (pos !== ta.selectionEnd) return;

    const lineStart = ta.value.lastIndexOf('\n', pos - 1) + 1;
    const before = ta.value.slice(lineStart, pos);

    // Inside a fenced code block Enter is just Enter.
    const fences = (ta.value.slice(0, lineStart).match(/^\s{0,3}```/gm) || []).length;
    if (fences % 2 === 1) return;

    let m = /^(\s{0,3})([-*+]|(\d{1,9})([.)]))(\s+)(.*)$/.exec(before);
    let prefix;
    let content;

    if (m) {
      const marker = m[3] !== undefined ? `${Number(m[3]) + 1}${m[4]}` : m[2];
      prefix = m[1] + marker + m[5];
      content = m[6];
    } else if ((m = /^(\s{0,3}>\s?)(.*)$/.exec(before))) {
      prefix = m[1];
      content = m[2];
    } else {
      return;
    }

    e.preventDefault();

    if (content.trim() === '') {
      // Empty item: drop its marker and stop the list.
      this.replace(lineStart, pos, '');
    } else {
      this.replace(pos, pos, `\n${prefix}`);
    }
  }
}

class YapThread {
  constructor(root) {
    this.root = root;
    this.cfg = JSON.parse(root.dataset.yap || '{}');
    this.list = root.querySelector('[data-yap-list]');
    this.empty = root.querySelector('[data-yap-empty]');
    this.home = root.querySelector('[data-yap-home]');
    this.form = root.querySelector('[data-yap-composer]');
    this.toasts = root.querySelector('[data-yap-toasts]');
    this.page = this.cfg.page || 1;
    this.sort = this.cfg.sort || 'newest';
    this.busy = new Set();

    root.classList.add('yap--js');

    if (root.dataset.theme === 'auto') {
      this.detectScheme();
      if (window.MutationObserver) {
        // Templates with a dark mode toggle usually flip a class or attribute on <html>/<body>.
        const mo = new MutationObserver(() => this.detectScheme());
        mo.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-bs-theme', 'data-theme', 'data-color-scheme', 'style'] });
        mo.observe(document.body, { attributes: true, attributeFilter: ['class', 'data-bs-theme', 'data-theme', 'style'] });
      }
    }

    if (this.toasts) {
      // Keep toasts out of transformed ancestors so position: fixed works.
      this.toasts.style.setProperty('--yap-accent', getComputedStyle(root).getPropertyValue('--yap-accent'));
      document.body.appendChild(this.toasts);
    }

    root.addEventListener('click', (e) => this.onClick(e));
    root.addEventListener('keydown', (e) => this.onKey(e));
    document.addEventListener('click', (e) => {
      if (!e.target.closest('[data-yap-menu]')) this.closeMenus();
    });

    this.initComposer();
    this.initSortGlider();
    this.observeEntrances(this.list.querySelectorAll(':scope > .yap-comment'));
    this.refreshTimes();
    setInterval(() => this.refreshTimes(), 60000);
    this.highlightFromHash();
    window.addEventListener('hashchange', () => this.highlightFromHash());
  }

  /**
   * "Automatic" theme: match whatever the page is actually painted with, so the
   * section never turns dark on a light template (or the other way round).
   */
  detectScheme() {
    let el = this.root.parentElement;
    let rgb = null;

    while (el) {
      const m = getComputedStyle(el).backgroundColor.match(/rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?/);
      if (m && (m[4] === undefined || Number(m[4]) > 0.5)) { rgb = m.slice(1, 4).map(Number); break; }
      el = el.parentElement;
    }

    // Nothing painted: the browser canvas shows through, which follows the page's color-scheme.
    if (!rgb) {
      const scheme = getComputedStyle(document.documentElement).colorScheme || '';
      const dark = scheme.includes('dark') && !scheme.includes('light');
      this.root.dataset.yapScheme = dark ? 'dark' : 'light';
      return;
    }

    const lum = (0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]) / 255;
    this.root.dataset.yapScheme = lum < 0.45 ? 'dark' : 'light';
  }

  /* ----------------------------------------------------------- network */

  async api(task, data = {}, method = 'POST') {
    const url = new URL(this.cfg.endpoint, window.location.href);
    url.searchParams.set('task', `api.${task}`);
    url.searchParams.set('context', this.cfg.context);
    url.searchParams.set('item', this.cfg.item);

    const opts = {
      method,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    };

    if (method === 'GET') {
      Object.entries(data).forEach(([k, v]) => url.searchParams.set(k, v));
    } else {
      const body = data instanceof FormData ? data : new FormData();
      if (!(data instanceof FormData)) Object.entries(data).forEach(([k, v]) => body.set(k, v));
      body.set(this.cfg.token, '1');
      opts.body = body;
    }

    let res;
    let json;

    try {
      res = await fetch(url, opts);
      json = await res.json();
    } catch (err) {
      throw new Error(T('NETWORK_ERROR', 'Network error, please try again.'));
    }

    if (!res.ok || !json || json.success === false) {
      throw new Error((json && json.message) || T('ERROR', 'Something went wrong.'));
    }

    return json.data;
  }

  async guard(key, fn) {
    if (this.busy.has(key)) return undefined;
    this.busy.add(key);
    try {
      return await fn();
    } catch (err) {
      this.toast(err.message, 'error');
      return undefined;
    } finally {
      this.busy.delete(key);
    }
  }

  /* ----------------------------------------------------------- events */

  onClick(e) {
    const t = e.target;
    const comment = t.closest('.yap-comment');
    let el;

    if ((el = t.closest('[data-yap-sort]'))) return this.changeSort(el.dataset.yapSort);
    if ((el = t.closest('[data-yap-more]'))) { e.preventDefault(); return this.loadMore(el); }
    if ((el = t.closest('[data-yap-vote]'))) return this.vote(comment, el);
    if (t.closest('[data-yap-reply]')) return this.openReply(comment);
    if (t.closest('[data-yap-cancel-reply]')) return this.closeReply(true);
    if (t.closest('[data-yap-share]')) return this.share(comment);
    if ((el = t.closest('.yap-menu__toggle'))) return this.toggleMenu(el);
    if (t.closest('[data-yap-edit]')) { this.closeMenus(); return this.edit(comment); }
    if (t.closest('[data-yap-delete]')) { this.closeMenus(); return this.remove(comment); }
    if ((el = t.closest('[data-yap-mod]'))) { this.closeMenus(); return this.moderate(comment, el.dataset.yapMod); }
    if ((el = t.closest('[data-yap-show-replies]'))) return this.showReplies(el);
    if ((el = t.closest('[data-yap-collapse]'))) return this.toggleThread(comment, el);
    if (t.closest('[data-yap-reveal]')) { comment.classList.remove('is-collapsed'); t.closest('[data-yap-reveal]').remove(); return undefined; }
    if ((el = t.closest('.yap-spoiler'))) return el.classList.add('is-revealed');
    if ((el = t.closest('[data-yap-tab]'))) return this.switchTab(el.dataset.yapTab);
    if ((el = t.closest('[data-yap-md]'))) return this.format(el.dataset.yapMd);
    if ((el = t.closest('[data-yap-permalink]')) && !e.ctrlKey && !e.metaKey) {
      e.preventDefault();
      window.history.replaceState(null, '', `#comment-${comment.dataset.id}`);
      return this.highlight(comment);
    }

    return undefined;
  }

  onKey(e) {
    if (e.key === 'Escape') {
      this.closeMenus();
      if (this.form && this.form.closest('[data-yap-reply-slot]')) this.closeReply(true);
    }

    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('yap-spoiler')) {
      e.preventDefault();
      e.target.classList.add('is-revealed');
    }
  }

  /* ----------------------------------------------------------- listing */

  initSortGlider() {
    const sort = this.root.querySelector('.yap-sort');
    if (!sort) return;
    const move = () => {
      const active = sort.querySelector('.is-active');
      const glider = sort.querySelector('.yap-sort__glider');
      if (!active || !glider) return;
      const a = active.getBoundingClientRect();
      const s = sort.getBoundingClientRect();
      const x = this.cfg.rtl ? a.right - s.right + 4 : a.left - s.left - 4;
      glider.style.setProperty('--x', `${x}px`);
      glider.style.setProperty('--w', `${a.width}px`);
    };
    this.moveGlider = move;
    move();
    window.addEventListener('resize', move);
  }

  async changeSort(sort) {
    if (sort === this.sort) return;
    this.sort = sort;

    this.root.querySelectorAll('[data-yap-sort]').forEach((b) => {
      const on = b.dataset.yapSort === sort;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    if (this.moveGlider) this.moveGlider();

    this.closeReply(false);
    this.list.style.opacity = '.4';
    this.list.style.transition = 'opacity .2s';

    await this.guard('sort', async () => {
      const data = await this.api('load', { page: 1, sort }, 'GET');
      this.list.innerHTML = data.html;
      this.page = 1;
      this.updateMore(data);
      this.afterInsert(this.list.querySelectorAll(':scope > .yap-comment'));
    });

    this.list.style.opacity = '';
  }

  async loadMore(btn) {
    await this.guard('more', async () => {
      btn.setAttribute('aria-busy', 'true');
      const skeletons = this.skeletons(2);
      this.list.append(...skeletons);
      try {
        const data = await this.api('load', { page: this.page + 1, sort: this.sort }, 'GET');
        skeletons.forEach((s) => s.remove());
        const frag = parse(data.html);
        const nodes = [...frag.children];
        nodes.forEach((n) => {
          const dup = this.root.querySelector(`#comment-${n.dataset.id}`);
          if (dup) n.remove();
        });
        this.list.append(frag);
        this.page = data.page;
        this.updateMore(data);
        this.afterInsert(nodes.filter((n) => n.isConnected), true);
      } finally {
        skeletons.forEach((s) => s.remove());
        btn.removeAttribute('aria-busy');
      }
    });
  }

  updateMore(data) {
    const btn = this.root.querySelector('[data-yap-more]');
    if (btn) {
      btn.parentElement.hidden = !data.hasMore;
      if (data.next) btn.href = data.next;
    }
    this.setCount(data.count);
    this.toggleEmpty();
  }

  skeletons(n) {
    return Array.from({ length: n }, () => {
      const el = document.createElement('div');
      el.className = 'yap-skeleton';
      el.setAttribute('aria-hidden', 'true');
      el.innerHTML = '<span class="yap-skeleton__a"></span><span class="yap-skeleton__b"><i></i><i></i><i></i></span>';
      return el;
    });
  }

  afterInsert(nodes, animate = true) {
    if (animate) this.observeEntrances(nodes);
    this.refreshTimes();
  }

  observeEntrances(nodes) {
    if (this.cfg.animations === 'none' || reducedMotion() || !('IntersectionObserver' in window)) return;
    if (!this.io) {
      this.io = new IntersectionObserver((entries) => {
        let i = 0;
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          entry.target.style.setProperty('--i', String(i++));
          entry.target.classList.add('is-entering');
          entry.target.addEventListener('animationend', () => entry.target.classList.remove('is-entering'), { once: true });
          this.io.unobserve(entry.target);
        });
      }, { rootMargin: '0px 0px -40px 0px' });
    }
    nodes.forEach((n) => this.io.observe(n));
  }

  setCount(count) {
    const el = this.root.querySelector('[data-yap-count]');
    if (!el || typeof count !== 'number' || String(count) === el.textContent.trim()) return;
    el.textContent = count;
    this.bump(el, 'is-bump');
  }

  toggleEmpty() {
    if (this.empty) this.empty.hidden = !!this.list.querySelector('.yap-comment');
  }

  bump(el, cls) {
    el.classList.remove(cls);
    void el.offsetWidth; // restart the animation
    el.classList.add(cls);
  }

  showReplies(btn) {
    const box = btn.parentElement;
    btn.remove();
    const hidden = [...box.querySelectorAll(':scope > .yap-comment')].slice(this.cfg.replyLimit || 3);
    this.observeEntrances(hidden);
  }

  toggleThread(comment, btn) {
    const collapsed = comment.classList.toggle('is-thread-collapsed');
    btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
  }

  /* ----------------------------------------------------------- votes */

  async vote(comment, btn) {
    if (btn.disabled || !comment) return;
    const box = btn.closest('[data-yap-votes]');
    const up = box.querySelector('[data-yap-vote="1"]');
    const down = box.querySelector('[data-yap-vote="-1"]');
    const value = Number(btn.dataset.yapVote);
    const current = up && up.classList.contains('is-active') ? 1 : (down && down.classList.contains('is-active') ? -1 : 0);
    const next = current === value ? 0 : value;
    const snapshot = this.readVotes(box);

    // Optimistic update.
    const likes = snapshot.likes + (next === 1) - (current === 1);
    const dislikes = snapshot.dislikes + (next === -1) - (current === -1);
    this.paintVotes(box, likes, dislikes, next);
    if (next !== 0) this.bump(btn, 'is-pop');

    await this.guard(`vote-${comment.dataset.id}`, async () => {
      try {
        const data = await this.api('vote', { id: comment.dataset.id, vote: next });
        this.paintVotes(box, data.likes, data.dislikes, data.vote);
      } catch (err) {
        this.paintVotes(box, snapshot.likes, snapshot.dislikes, current);
        throw err;
      }
    });
  }

  readVotes(box) {
    const num = (sel) => Number((box.querySelector(sel) || {}).textContent || 0);
    const score = box.querySelector('[data-yap-score]');
    return {
      likes: box.dataset.likes ? Number(box.dataset.likes) : (score ? Math.max(0, Number(score.textContent)) : num('[data-yap-likes]')),
      dislikes: box.dataset.dislikes ? Number(box.dataset.dislikes) : (score ? Math.max(0, -Number(score.textContent)) : num('[data-yap-dislikes]')),
    };
  }

  paintVotes(box, likes, dislikes, vote) {
    box.dataset.likes = likes;
    box.dataset.dislikes = dislikes;

    const set = (sel, value) => {
      const el = box.querySelector(sel);
      if (el && el.textContent.trim() !== String(value)) {
        el.textContent = value;
        this.bump(el, 'is-roll');
      }
    };

    set('[data-yap-likes]', likes);
    set('[data-yap-dislikes]', dislikes);

    const score = box.querySelector('[data-yap-score]');
    if (score) {
      set('[data-yap-score]', likes - dislikes);
      score.classList.toggle('is-positive', likes - dislikes > 0);
      score.classList.toggle('is-negative', likes - dislikes < 0);
    }

    box.querySelectorAll('[data-yap-vote]').forEach((b) => {
      const on = Number(b.dataset.yapVote) === vote;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  /* ----------------------------------------------------------- composer */

  initComposer() {
    const form = this.form;
    if (!form) return;

    this.textarea = form.querySelector('textarea[name="body"]');
    this.counter = form.querySelector('[data-yap-counter]');
    this.preview = form.querySelector('[data-yap-preview]');

    if (form.hasAttribute('data-yap-reply-only')) form.hidden = true;

    this.editor = new YapEditor(this.textarea, Number(this.cfg.flags) || 0);
    this.textarea.addEventListener('input', () => this.onInput());
    this.textarea.addEventListener('keydown', (e) => {
      const mod = e.ctrlKey || e.metaKey;
      if (mod && e.key === 'Enter') { e.preventDefault(); form.requestSubmit(); }
      if (mod && ['b', 'i', 'k'].includes(e.key.toLowerCase())) {
        const tool = { b: 'bold', i: 'italic', k: 'link' }[e.key.toLowerCase()];
        if (form.querySelector(`[data-yap-md="${tool}"]`)) { e.preventDefault(); this.format(tool); }
      }
    });

    form.addEventListener('submit', (e) => { e.preventDefault(); this.submit(); });
    this.onInput();
  }

  onInput() {
    const ta = this.textarea;
    ta.classList.remove('is-invalid');
    this.editor.box.classList.remove('is-invalid');

    if (!CSS.supports('field-sizing', 'content')) {
      ta.style.height = 'auto';
      ta.style.height = `${Math.min(ta.scrollHeight + 2, window.innerHeight * 0.6)}px`;
    }

    if (this.counter) {
      const max = Number(this.counter.dataset.max) || this.cfg.maxLength;
      const left = max - [...ta.value].length;
      this.counter.textContent = sprintf(T('CHARS_LEFT', '%d characters left'), left);
      this.counter.classList.toggle('is-warn', left < max * 0.1 && left >= 0);
      this.counter.classList.toggle('is-over', left < 0);
    }
  }

  switchTab(tab) {
    const form = this.form;
    form.querySelectorAll('[data-yap-tab]').forEach((b) => {
      const on = b.dataset.yapTab === tab;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });

    const previewing = tab === 'preview';
    this.editor.box.hidden = previewing;
    this.preview.hidden = !previewing;
    form.querySelectorAll('[data-yap-md]').forEach((b) => { b.disabled = previewing; });

    if (!previewing) { this.textarea.focus(); return; }

    const body = this.textarea.value.trim();
    if (!body) { this.preview.innerHTML = `<p class="yap-muted">${T('PREVIEW_EMPTY', 'Nothing to preview yet.')}</p>`; return; }

    this.preview.innerHTML = `<p class="yap-muted">${T('LOADING', 'Loading…')}</p>`;
    this.guard('preview', async () => {
      const data = await this.api('preview', { body });
      this.preview.innerHTML = data.html;
    });
  }

  format(tool) {
    const ta = this.textarea;
    if (!ta || this.editor.box.hidden) return;

    const ed = this.editor;
    const start = ta.selectionStart;
    const end = ta.selectionEnd;
    const sel = ta.value.slice(start, end);
    const wrap = (l, r = l, placeholder = '') => {
      const inner = sel || placeholder;
      ed.replace(start, end, l + inner + r);
      if (!sel && placeholder) ta.setSelectionRange(start + l.length, start + l.length + inner.length);
    };
    const prefix = (fn) => {
      const lineStart = ta.value.lastIndexOf('\n', start - 1) + 1;
      const block = ta.value.slice(lineStart, end);
      const out = block.split('\n').map((line, i) => fn(i) + line).join('\n');
      ed.replace(lineStart, end, out);
    };

    switch (tool) {
      case 'bold': wrap('**', '**', 'bold'); break;
      case 'italic': wrap('*', '*', 'italic'); break;
      case 'strike': wrap('~~', '~~', 'text'); break;
      case 'spoiler': wrap('||', '||', 'spoiler'); break;
      case 'code':
        if (sel.includes('\n')) wrap('```\n', '\n```');
        else wrap('`', '`', 'code');
        break;
      case 'quote': prefix(() => '> '); break;
      case 'list': prefix(() => '- '); break;
      case 'olist': prefix((i) => `${i + 1}. `); break;
      case 'link': {
        const url = window.prompt(T('LINK_PROMPT', 'Link address (https://…)'), 'https://');
        if (url && /^https?:\/\/\S+$/i.test(url)) ed.replace(start, end, `[${sel || url}](${url})`);
        break;
      }
      case 'image': {
        const url = window.prompt(T('IMAGE_PROMPT', 'Image address (https://…)'), 'https://');
        if (url && /^https:\/\/\S+$/i.test(url)) ed.replace(start, end, `![${sel || 'image'}](${url})`);
        break;
      }
      default:
    }

    ta.focus();
    this.onInput();
  }

  openReply(comment) {
    const form = this.form;
    if (!form || !comment) return;

    const slot = comment.querySelector(':scope > .yap-comment__card [data-yap-reply-slot]');
    if (form.parentElement === slot) { this.textarea.focus(); return; }

    slot.appendChild(form);
    form.hidden = false;
    form.elements.parent_id.value = comment.dataset.id;

    const bar = form.querySelector('[data-yap-replying]');
    form.querySelector('[data-yap-replying-text]').textContent = sprintf(T('REPLYING_TO', 'Replying to %s'), comment.dataset.author || '');
    bar.hidden = false;

    if (this.preview && !this.preview.hidden) this.switchTab('write');
    this.textarea.focus({ preventScroll: true });
    form.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'nearest' });
  }

  closeReply(focus) {
    const form = this.form;
    if (!form || form.parentElement === this.home) return;

    this.home.appendChild(form);
    form.elements.parent_id.value = '0';
    form.querySelector('[data-yap-replying]').hidden = true;
    if (form.hasAttribute('data-yap-reply-only')) form.hidden = true;
    if (focus && !form.hidden) this.textarea.focus({ preventScroll: true });
  }

  async submit() {
    const form = this.form;
    const ta = this.textarea;
    const len = [...ta.value.trim()].length;

    for (const field of form.querySelectorAll('.yap-guest input')) {
      if (!field.checkValidity()) {
        field.classList.add('is-invalid');
        field.focus();
        field.addEventListener('input', () => field.classList.remove('is-invalid'), { once: true });
        return;
      }
    }

    if (len < this.cfg.minLength) {
      ta.classList.add('is-invalid');
      this.editor.box.classList.add('is-invalid');
      this.toast(sprintf(T('TOO_SHORT', 'Please write at least %d characters.'), this.cfg.minLength), 'warn');
      ta.focus();
      return;
    }

    const btn = form.querySelector('[data-yap-submit]');

    await this.guard('post', async () => {
      btn.disabled = true;
      btn.classList.add('is-loading');

      try {
        const data = await this.api('post', new FormData(form));

        if (data.stamp) form.elements.yap_ts.value = data.stamp;
        ta.value = '';
        this.editor.render();
        if (this.preview) this.preview.innerHTML = '';
        if (this.preview && !this.preview.hidden) this.switchTab('write');
        this.onInput();
        this.resetCaptcha();

        const parentId = Number(data.parentId);
        this.closeReply(false);

        if (data.html) {
          const frag = parse(data.html);
          const node = frag.firstElementChild;

          if (parentId) {
            const parent = this.root.querySelector(`#comment-${parentId}`);
            const box = parent && parent.querySelector(':scope > [data-yap-replies]');
            (box || this.list).append(frag);
          } else if (this.sort === 'oldest') {
            this.list.append(frag);
          } else {
            const firstUnpinned = [...this.list.children].find((c) => !c.classList.contains('is-pinned'));
            this.list.insertBefore(frag, firstUnpinned || null);
          }

          if (node) {
            node.classList.add('is-new');
            this.afterInsert([node]);
            this.toggleEmpty();
            const r = node.getBoundingClientRect();
            if (r.top < 0 || r.bottom > window.innerHeight) {
              node.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'center' });
            }
          }
        }

        this.setCount(data.count);
        this.toast(data.message || T('POSTED', 'Comment posted!'), data.pending ? 'warn' : 'success');
      } finally {
        btn.disabled = false;
        btn.classList.remove('is-loading');
      }
    });
  }

  resetCaptcha() {
    try {
      if (window.grecaptcha && typeof window.grecaptcha.reset === 'function') window.grecaptcha.reset();
      if (window.hcaptcha && typeof window.hcaptcha.reset === 'function') window.hcaptcha.reset();
      if (window.turnstile && typeof window.turnstile.reset === 'function') window.turnstile.reset();
    } catch (e) { /* captcha not rendered */ }
  }

  /* ----------------------------------------------------------- edit / delete / moderate */

  async edit(comment) {
    const body = comment.querySelector(':scope > .yap-comment__card [data-yap-body]');
    if (!body || comment.querySelector('.yap-inline-edit')) return;

    await this.guard(`edit-${comment.dataset.id}`, async () => {
      const data = await this.api('source', { id: comment.dataset.id }, 'GET');

      const box = document.createElement('div');
      box.className = 'yap-inline-edit';
      box.innerHTML = `<textarea class="yap-textarea" rows="4" maxlength="${this.cfg.maxLength}"></textarea>
        <div class="yap-inline-edit__actions">
          <button type="button" class="yap-btn yap-btn--ghost" data-act="cancel">${T('CANCEL', 'Cancel')}</button>
          <button type="button" class="yap-btn yap-btn--primary" data-act="save"><span>${T('SAVE', 'Save')}</span></button>
        </div>`;

      const ta = box.querySelector('textarea');
      ta.value = data.body;
      body.hidden = true;
      body.after(box);
      // eslint-disable-next-line no-new
      new YapEditor(ta, Number(this.cfg.flags) || 0);
      ta.focus();
      ta.setSelectionRange(ta.value.length, ta.value.length);

      const close = () => { box.remove(); body.hidden = false; };

      box.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { e.stopPropagation(); close(); }
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') box.querySelector('[data-act="save"]').click();
      });

      box.addEventListener('click', async (e) => {
        const act = e.target.closest('[data-act]');
        if (!act) return;
        if (act.dataset.act === 'cancel') { close(); return; }

        act.disabled = true;
        act.classList.add('is-loading');
        await this.guard(`save-${comment.dataset.id}`, async () => {
          const saved = await this.api('edit', { id: comment.dataset.id, body: ta.value });
          this.replaceCard(comment, saved.html);
          this.toast(T('SAVED', 'Saved.'));
        });
        act.disabled = false;
        act.classList.remove('is-loading');
      });
    });
  }

  async remove(comment) {
    if (!window.confirm(T('CONFIRM_DELETE', 'Delete this comment?'))) return;

    await this.guard(`del-${comment.dataset.id}`, async () => {
      const data = await this.api('remove', { id: comment.dataset.id });
      if (data.removed) this.dropComment(comment);
      else this.replaceCard(comment, data.html);
      this.setCount(data.count);
      this.toast(data.message || T('DELETED', 'Deleted.'));
    });
  }

  async moderate(comment, action) {
    if ((action === 'delete' || action === 'spam') && !window.confirm(T('CONFIRM_DELETE', 'Are you sure?'))) return;

    await this.guard(`mod-${comment.dataset.id}`, async () => {
      const data = await this.api('moderate', { id: comment.dataset.id, action });
      if (data.removed) this.dropComment(comment);
      else this.replaceCard(comment, data.html);
      this.setCount(data.count);

      const messages = { approve: 'APPROVED', spam: 'SPAMMED', pin: 'PINNED', unpin: 'UNPINNED', delete: 'DELETED' };
      if (messages[action]) this.toast(T(messages[action], 'Done.'));
    });
  }

  /** Swap a comment's own card for fresh markup, keeping its loaded replies. */
  replaceCard(comment, html) {
    if (!html) return;
    const fresh = parse(html).firstElementChild;
    if (!fresh) return;

    const oldCard = comment.querySelector(':scope > .yap-comment__card');
    const newCard = fresh.querySelector(':scope > .yap-comment__card');
    const keepSlot = oldCard.querySelector('[data-yap-reply-slot]');

    // Keep the composer if it is currently open under this comment.
    if (keepSlot && this.form && keepSlot.contains(this.form)) {
      newCard.querySelector('[data-yap-reply-slot]').appendChild(this.form);
    }

    comment.className = fresh.className;
    oldCard.replaceWith(newCard);
    this.bump(comment, 'is-new');
    this.refreshTimes();
  }

  dropComment(comment) {
    if (this.form && comment.contains(this.form)) this.closeReply(false);
    const finish = () => { comment.remove(); this.toggleEmpty(); };
    if (this.cfg.animations === 'none' || reducedMotion()) { finish(); return; }
    comment.style.overflow = 'hidden';
    comment.classList.add('is-leaving');
    comment.addEventListener('animationend', finish, { once: true });
    setTimeout(finish, 600);
  }

  /* ----------------------------------------------------------- misc */

  toggleMenu(toggle) {
    const list = toggle.nextElementSibling;
    const open = list.hidden;
    this.closeMenus();
    list.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { const first = list.querySelector('button'); if (first) first.focus(); }
  }

  closeMenus() {
    this.root.querySelectorAll('.yap-menu__list:not([hidden])').forEach((m) => {
      m.hidden = true;
      m.previousElementSibling.setAttribute('aria-expanded', 'false');
    });
  }

  async share(comment) {
    const link = comment.querySelector('[data-yap-permalink]');
    const url = link ? link.dataset.yapPermalink : `${window.location.href.split('#')[0]}#comment-${comment.dataset.id}`;

    try {
      await navigator.clipboard.writeText(url);
      this.toast(T('COPIED', 'Link copied to clipboard.'));
    } catch (e) {
      window.location.hash = `comment-${comment.dataset.id}`;
    }
  }

  highlightFromHash() {
    const m = /^#comment-(\d+)$/.exec(window.location.hash);
    if (!m) return;
    const el = this.root.querySelector(`#comment-${m[1]}`);
    if (!el) return;

    // Reveal hidden ancestors (collapsed reply lists, collapsed threads).
    let p = el.parentElement;
    while (p && p !== this.root) {
      if (p.matches('[data-yap-replies]')) {
        const more = p.querySelector(':scope > [data-yap-show-replies]');
        if (more) this.showReplies(more);
      }
      if (p.classList.contains('is-thread-collapsed')) p.classList.remove('is-thread-collapsed');
      p = p.parentElement;
    }

    el.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'center' });
    this.highlight(el);
  }

  highlight(el) {
    this.bump(el, 'is-highlight');
  }

  refreshTimes() {
    if (!this.cfg.relative || !('RelativeTimeFormat' in Intl)) return;

    let rtf;
    try {
      rtf = new Intl.RelativeTimeFormat(this.cfg.lang || document.documentElement.lang || undefined, { numeric: 'auto' });
    } catch (e) {
      rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    }

    const units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];

    this.root.querySelectorAll('time[data-yap-time]').forEach((el) => {
      const then = Date.parse(el.getAttribute('datetime'));
      if (Number.isNaN(then)) return;
      const diff = Math.round((then - Date.now()) / 1000);
      const abs = Math.abs(diff);

      if (abs < 45) { el.textContent = T('JUST_NOW', 'just now'); return; }

      for (const [unit, secs] of units) {
        if (abs >= secs || unit === 'minute') {
          el.textContent = rtf.format(Math.round(diff / secs), unit);
          return;
        }
      }
    });
  }

  toast(message, type = 'success') {
    if (!this.toasts || !message) return;
    const el = document.createElement('div');
    el.className = `yap-toast yap-toast--${type}`;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.textContent = message;
    this.toasts.appendChild(el);

    setTimeout(() => {
      el.classList.add('is-leaving');
      el.addEventListener('animationend', () => el.remove(), { once: true });
      setTimeout(() => el.remove(), 500);
    }, type === 'error' ? 5000 : 3200);
  }
}

const boot = () => {
  document.querySelectorAll('.yap[data-yap]:not(.yap--js)').forEach((root) => {
    try {
      root.yap = new YapThread(root);
    } catch (e) {
      // eslint-disable-next-line no-console
      console.error('YapComments:', e);
    }
  });
};

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
else boot();
