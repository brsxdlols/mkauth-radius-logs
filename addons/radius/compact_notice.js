(function () {
    window.mkaCompactNotice = function (title, message) {
        var previous = document.activeElement;
        var dialog = document.createElement('dialog');
        var heading = document.createElement('h3');
        var text = document.createElement('p');
        var close = document.createElement('button');

        dialog.setAttribute('aria-label', title);
        dialog.style.cssText = 'box-sizing:border-box;width:min(420px,90vw);padding:24px;border:1px solid #dce5f1;border-radius:16px;background:#fff;color:#18324a;box-shadow:0 24px 70px #0004;font:14px/1.5 Arial,sans-serif';
        heading.style.cssText = 'margin:0 0 10px;font-size:18px';
        heading.textContent = title;
        text.textContent = message;
        close.type = 'button';
        close.textContent = 'Entendi';
        close.style.cssText = 'float:right;border:0;border-radius:9px;padding:10px 18px;background:#2563eb;color:#fff;cursor:pointer;font-weight:600';
        close.onclick = function () {
            dialog.close();
        };

        dialog.appendChild(heading);
        dialog.appendChild(text);
        dialog.appendChild(close);
        document.body.appendChild(dialog);
        dialog.addEventListener('close', function () {
            dialog.parentNode.removeChild(dialog);
            if (previous) {
                previous.focus();
            }
        });

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            window.alert(title + '\n\n' + message);
            dialog.parentNode.removeChild(dialog);
        }
    };
}());
