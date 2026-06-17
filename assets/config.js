document
    .querySelector('[name=generate-key]')
    .addEventListener('click', async () => {
    const response = await blessing.fetch.post('/admin/plugins/config/yggdrasil-api/generate');
    if (response.code === 0) {
        blessing.notify.toast.success(trans('yggdrasil-api.key-generated'));
        document.querySelector('td.value textarea').value =
            response.key;
        const form = document.querySelector('input[value=keypair]')
            .parentElement;
        form.submit();
    }
    else {
        blessing.notify.toast.error(response.message);
    }
});
