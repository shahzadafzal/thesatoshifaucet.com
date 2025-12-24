
  function copyInvoiceToClipboard(btn, c) {
    // textarea is the previous sibling in the same container
    var container = btn.parentNode;
    var textarea = container.querySelector('.'+c);
    if (!textarea) return;

    var text = textarea.value;

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        btn.textContent = 'Copied!';
        setTimeout(function () {
          btn.textContent = 'Copy invoice';
        }, 1500);
      }).catch(function () {
        fallbackCopy(textarea, btn);
      });
    } else {
      fallbackCopy(textarea, btn);
    }
  }


  function fallbackCopy(textarea, btn) {
    textarea.focus();
    textarea.select();
    try {
      var ok = document.execCommand('copy');
      if (ok) {
        btn.textContent = 'Copied!';
        setTimeout(function () {
          btn.textContent = 'Copy invoice';
        }, 1500);
      } else {
        alert('Copy failed – please copy manually.');
      }
    } catch (e) {
      alert('Copy failed – please copy manually.');
    }
    // Deselect text
    window.getSelection().removeAllRanges();
  }


  // Refresh available balance display

  function formatSats(n) {
    try { return Number(n).toLocaleString(); } catch { return String(n); }
  }

  function refreshBalance() {
    fetch("balance.php", { cache: "no-store" })
      .then(r => r.json())
      .then(data => {
        const el = document.getElementById("available-balance");
        if (!el) return;

        if (data && data.ok) {
          el.textContent = "▲ " + formatSats(data.balance) + " satoshis available";
        }
      })
      .catch(() => {});
  }

  