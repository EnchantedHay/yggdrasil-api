var _a;
document.body.addEventListener('dragstart', (event) => {
    if (!event.target || !(event.target instanceof HTMLElement)) {
        return;
    }
    const button = event.target.closest('#ygg-dnd-button');
    if (button) {
        const serverUrl = button.dataset.clipboardText;
        const uri = 'authlib-injector:yggdrasil-server:' + encodeURIComponent(serverUrl);
        if (event.dataTransfer) {
            event.dataTransfer.setData('text/plain', uri);
            event.dataTransfer.dropEffect = 'copy';
        }
    }
});
(_a = document
    .querySelector('#ygg-dnd-button')) === null || _a === void 0 ? void 0 : _a.addEventListener('click', function (event) {
    if (this.disabled) {
        return;
    }
    const content = this.dataset.clipboardText;
    const input = document.createElement('input');
    input.style.visibility = 'none';
    input.value = content;
    document.body.appendChild(input);
    input.select();
    document.execCommand('copy');
    input.remove();
    const originalContent = this.textContent;
    const originalHTML = this.innerHTML;
    this.disabled = true;
    this.innerHTML = `<i class="fas fa-check mr-1"></i>${trans('yggdrasil-api.copied')}`;
    setTimeout(() => {
        this.innerHTML = originalHTML;
        this.disabled = false;
    }, 1000);
});
