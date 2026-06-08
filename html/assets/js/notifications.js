document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-notification-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
      const value = button.getAttribute('data-notification-copy') || '';
      try {
        await navigator.clipboard.writeText(value);
        button.textContent = 'Скопійовано';
      } catch (error) {
        button.textContent = 'Не вдалося';
      }
    });
  });
});
