/* ── SEO Аудитор — основной JS ── */

// ── Форма на главной ──────────────────────────────────────────────────────────
const auditForm = document.getElementById('auditForm');
if (auditForm) {
  auditForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const urlInput   = document.getElementById('url');
    const emailInput = document.getElementById('email');
    const submitBtn  = document.getElementById('submitBtn');
    const btnText    = submitBtn.querySelector('.btn-text');
    const btnLoading = submitBtn.querySelector('.btn-loading');
    const formError  = document.getElementById('formError');

    formError.style.display = 'none';
    submitBtn.disabled = true;
    btnText.style.display = 'none';
    btnLoading.style.display = '';

    try {
      const captchaToken = window.smartCaptcha ? window.smartCaptcha.getResponse() : '';
      if (!captchaToken) throw new Error('Пройдите проверку — нажмите на капчу');

      const res = await fetch('/api/start.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url: urlInput.value.trim(), email: emailInput.value.trim(), captchaToken }),
      });
      const data = await res.json();

      if (!res.ok || data.error) {
        throw new Error(data.error || 'Ошибка запуска аудита');
      }

      window.location.href = '/audit.php?id=' + encodeURIComponent(data.audit_id);
    } catch (err) {
      formError.textContent = err.message;
      formError.style.display = 'block';
      submitBtn.disabled = false;
      btnText.style.display = '';
      btnLoading.style.display = 'none';
      if (window.smartCaptcha) window.smartCaptcha.reset();
    }
  });
}

// ── Страница прогресса ────────────────────────────────────────────────────────
if (typeof AUDIT_ID !== 'undefined' && AUDIT_ID) {
  const elTitle    = document.getElementById('progressTitle');
  const elUrl      = document.getElementById('progressUrl');
  const elBar      = document.getElementById('progressBar');
  const elPercent  = document.getElementById('progressPercent');
  const elText     = document.getElementById('progressText');
  const elSpinner  = document.getElementById('progressSpinner');
  const elProgress = document.getElementById('progressSection');
  const elResult   = document.getElementById('resultSection');
  const elError    = document.getElementById('errorSection');
  const elSteps    = document.querySelectorAll('.step');

  let pollInterval;

  function updateSteps(progress) {
    elSteps.forEach(step => {
      const from = parseInt(step.dataset.from);
      const to   = parseInt(step.dataset.to);
      const st   = step.querySelector('.step-status');
      if (progress >= to) {
        step.classList.remove('active'); step.classList.add('done');
        st.className = 'step-status done-st'; st.textContent = '✓';
      } else if (progress >= from) {
        step.classList.add('active'); step.classList.remove('done');
        st.className = 'step-status running'; st.textContent = '●';
      } else {
        step.classList.remove('active', 'done');
        st.className = 'step-status waiting'; st.textContent = '•';
      }
    });
  }

  async function poll() {
    try {
      const res  = await fetch('/api/status.php?id=' + encodeURIComponent(AUDIT_ID));
      const data = await res.json();

      if (data.error) { showError(data.error); clearInterval(pollInterval); return; }

      const p = data.progress || 0;
      elBar.style.width     = p + '%';
      elPercent.textContent = p + '%';
      elText.textContent    = data.progress_text || '';
      updateSteps(p);

      if (data.pages_crawled > 0) {
        const crawlStep = document.getElementById('step-crawl');
        if (crawlStep) {
          const desc = crawlStep.querySelector('#step-crawl-desc');
          if (desc) desc.textContent = 'Найдено страниц: ' + data.pages_crawled;
        }
      }

      if (data.status === 'done') {
        clearInterval(pollInterval);
        showResult(data);
      } else if (data.status === 'error') {
        clearInterval(pollInterval);
        showError(data.error_message || 'Неизвестная ошибка');
      }
    } catch (err) {
      console.error('Poll error:', err);
    }
  }

  function showResult(data) {
    elBar.style.width     = '100%';
    elPercent.textContent = '100%';
    elText.textContent    = 'Готово! Открываем отчёт...';
    updateSteps(100);
    setTimeout(() => {
      window.location.href = '/api/report.php?id=' + encodeURIComponent(AUDIT_ID);
    }, 700);
  }

  function showError(msg) {
    elProgress.style.display = 'none';
    elError.style.display    = '';
    document.getElementById('errorText').textContent = msg || 'Ошибка при выполнении аудита';
  }

  // Первый вызов немедленно, затем каждые 2 секунды
  poll();
  pollInterval = setInterval(poll, 2000);
}
