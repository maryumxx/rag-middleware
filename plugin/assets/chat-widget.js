/**
 * RAG Business Chatbot — Widget
 * Vanilla JS, no dependencies.
 */
(function () {
  'use strict';

  // ── Bootstrap ────────────────────────────────────────────────────────────

  function init() {
    const root = document.getElementById('rag-chatbot-root');
    if (!root) return;

    const config = {
      endpoint: root.dataset.endpoint || '/wp-json/rag-chatbot/v1/chat',
      greeting: root.dataset.greeting || 'Hi! Ask me anything.',
      color:    root.dataset.color    || '#1a1a2e',
      position: root.dataset.position || 'bottom-right',
    };

    // Apply accent color to CSS custom property
    document.documentElement.style.setProperty('--rag-accent', config.color);

    const widget = new ChatWidget(config);
    widget.mount();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // ── ChatWidget class ──────────────────────────────────────────────────────

  function ChatWidget(config) {
    this.config    = config;
    this.history   = [];       // [{role:'user'|'assistant', content:'...'}]
    this.isOpen    = false;
    this.isLoading = false;
    this.greetingSeen = false;
    this.elements  = {};
  }

  ChatWidget.prototype.mount = function () {
    this._buildDOM();
    this._attachEvents();
    this._showGreeting();
  };

  // ── DOM Construction ──────────────────────────────────────────────────────

  ChatWidget.prototype._buildDOM = function () {
    const pos      = this.config.position === 'bottom-left' ? 'left' : 'right';
    const posClass = 'rag-chatbot-trigger--' + pos;

    // Trigger button
    const trigger = createElement('button', {
      type: 'button',
      'aria-label': 'Open chat',
      class: 'rag-chatbot-trigger rag-chatbot-trigger--pulse ' + posClass,
      style: 'background:' + this.config.color,
    });
    trigger.innerHTML = svgChat() + '<span class="rag-chatbot-unread rag-chatbot-unread--visible"></span>';

    // Panel
    const panel = createElement('div', {
      role: 'dialog',
      'aria-label': 'Chat with us',
      class: 'rag-chatbot-panel rag-chatbot-panel--' + pos,
    });

    // Header
    const header = createElement('div', { class: 'rag-chatbot-header' });
    const titleWrap = createElement('div');
    titleWrap.innerHTML =
      '<p class="rag-chatbot-header-title">Ask us anything</p>' +
      '<p class="rag-chatbot-header-subtitle">We usually reply instantly</p>';

    const closeBtn = createElement('button', {
      type: 'button',
      'aria-label': 'Close chat',
      class: 'rag-chatbot-close',
    });
    closeBtn.innerHTML = svgClose();
    header.appendChild(titleWrap);
    header.appendChild(closeBtn);

    // Messages area
    const messages = createElement('div', {
      class: 'rag-chatbot-messages',
      role: 'log',
      'aria-live': 'polite',
    });

    // Footer
    const footer = createElement('div', { class: 'rag-chatbot-footer' });
    const input = createElement('textarea', {
      class: 'rag-chatbot-input',
      placeholder: 'Type a message…',
      rows: '1',
      'aria-label': 'Message',
      maxlength: '500',
    });
    const sendBtn = createElement('button', {
      type: 'button',
      class: 'rag-chatbot-send',
      'aria-label': 'Send message',
      style: 'background:' + this.config.color,
    });
    sendBtn.innerHTML = svgSend();
    footer.appendChild(input);
    footer.appendChild(sendBtn);

    panel.appendChild(header);
    panel.appendChild(messages);
    panel.appendChild(footer);

    document.body.appendChild(trigger);
    document.body.appendChild(panel);

    this.elements = { trigger, panel, messages, input, sendBtn, closeBtn };
  };

  // ── Event Binding ─────────────────────────────────────────────────────────

  ChatWidget.prototype._attachEvents = function () {
    const { trigger, closeBtn, input, sendBtn } = this.elements;

    trigger.addEventListener('click', () => this._toggle());
    closeBtn.addEventListener('click', () => this._close());

    sendBtn.addEventListener('click', () => this._send());

    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        this._send();
      }
    });

    // Auto-resize textarea
    input.addEventListener('input', () => {
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    });
  };

  // ── Open / Close ──────────────────────────────────────────────────────────

  ChatWidget.prototype._toggle = function () {
    if (this.isOpen) {
      this._close();
    } else {
      this._open();
    }
  };

  ChatWidget.prototype._open = function () {
    const { trigger, panel, input } = this.elements;
    this.isOpen = true;
    panel.classList.add('rag-chatbot-panel--open');
    trigger.classList.remove('rag-chatbot-trigger--pulse');
    trigger.setAttribute('aria-label', 'Close chat');

    // Hide unread dot once opened
    const dot = trigger.querySelector('.rag-chatbot-unread');
    if (dot) dot.classList.remove('rag-chatbot-unread--visible');

    this.greetingSeen = true;
    this._scrollToBottom();
    setTimeout(() => input.focus(), 220);
  };

  ChatWidget.prototype._close = function () {
    const { trigger, panel } = this.elements;
    this.isOpen = false;
    panel.classList.remove('rag-chatbot-panel--open');
    trigger.classList.add('rag-chatbot-trigger--pulse');
    trigger.setAttribute('aria-label', 'Open chat');
  };

  // ── Greeting ──────────────────────────────────────────────────────────────

  ChatWidget.prototype._showGreeting = function () {
    this._appendBotMessage(this.config.greeting);
  };

  // ── Send & Receive ────────────────────────────────────────────────────────

  ChatWidget.prototype._send = function () {
    const { input, sendBtn } = this.elements;
    const text = input.value.trim();
    if (!text || this.isLoading) return;

    // Clear input
    input.value = '';
    input.style.height = 'auto';

    // Show user bubble
    this._appendUserMessage(text);

    // Update history
    this.history.push({ role: 'user', content: text });

    // Show typing indicator
    this._setLoading(true);
    sendBtn.disabled = true;

    // Trim history to last 10 turns before sending
    const historyToSend = this.history.slice(-10);

    const self = this;
    fetch(this.config.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: text, history: historyToSend }),
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        self._setLoading(false);
        sendBtn.disabled = false;

        const answer = (data && data.answer) ? data.answer : 'Something went wrong. Please try again.';
        self._appendBotMessage(answer);
        self.history.push({ role: 'assistant', content: answer });
      })
      .catch(function () {
        self._setLoading(false);
        sendBtn.disabled = false;
        self._appendBotMessage('Something went wrong. Please try again.');
      });
  };

  // ── UI helpers ────────────────────────────────────────────────────────────

  ChatWidget.prototype._appendUserMessage = function (text) {
    const { messages } = this.elements;
    const bubble = createElement('div', { class: 'rag-chatbot-msg rag-chatbot-msg--user' });
    bubble.textContent = text;
    messages.appendChild(bubble);
    this._scrollToBottom();
  };

  ChatWidget.prototype._appendBotMessage = function (text) {
    const { messages } = this.elements;
    const bubble = createElement('div', { class: 'rag-chatbot-msg rag-chatbot-msg--bot' });
    bubble.textContent = text;
    messages.appendChild(bubble);
    this._scrollToBottom();
  };

  ChatWidget.prototype._setLoading = function (loading) {
    const { messages } = this.elements;
    this.isLoading = loading;

    if (loading) {
      const typing = createElement('div', {
        class: 'rag-chatbot-typing',
        id: 'rag-typing-indicator',
      });
      typing.innerHTML = '<span></span><span></span><span></span>';
      messages.appendChild(typing);
    } else {
      const existing = document.getElementById('rag-typing-indicator');
      if (existing) existing.remove();
    }

    this._scrollToBottom();
  };

  ChatWidget.prototype._scrollToBottom = function () {
    const { messages } = this.elements;
    // Use requestAnimationFrame so the DOM has updated before we scroll
    requestAnimationFrame(function () {
      messages.scrollTop = messages.scrollHeight;
    });
  };

  // ── Utility ───────────────────────────────────────────────────────────────

  function createElement(tag, attrs) {
    const el = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        el.setAttribute(k, attrs[k]);
      });
    }
    return el;
  }

  function svgChat() {
    return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
      '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" fill="white"/>' +
      '</svg>';
  }

  function svgClose() {
    return '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">' +
      '<path d="M2 2l12 12M14 2L2 14" stroke="white" stroke-width="2" stroke-linecap="round"/>' +
      '</svg>';
  }

  function svgSend() {
    return '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">' +
      '<path d="M16 2L1 8.5l6 1.5 2 6L16 2z" fill="white"/>' +
      '</svg>';
  }

})();
