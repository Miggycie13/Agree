document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }
    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

var priceData = document.getElementById('price-data');
if (priceData && window.Chart) {
    var parsed = JSON.parse(priceData.textContent || '{}');
    var canvas = document.getElementById('priceChart');
    if (canvas && parsed.labels) {
        var styles = {
            live_chicken: '#1e5631',
            dressed_chicken: '#c8922a',
            table_eggs: '#8c4a2f'
        };
        var names = {
            live_chicken: 'Live chicken / head',
            dressed_chicken: 'Dressed chicken / kg',
            table_eggs: 'Table eggs / tray'
        };
        var datasets = Object.keys(parsed.series || {}).map(function (key) {
            return {
                label: names[key] || key,
                data: parsed.series[key],
                borderColor: styles[key] || '#1e5631',
                backgroundColor: styles[key] || '#1e5631',
                spanGaps: true,
                tension: 0.25,
                pointRadius: 2
            };
        });
        new window.Chart(canvas, {
            type: 'line',
            data: { labels: parsed.labels, datasets: datasets },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    y: {
                        ticks: {
                            callback: function (value) {
                                return '₱' + value;
                            }
                        }
                    }
                }
            }
        });
    }
}

var productPick = document.getElementById('product_id');
if (productPick) {
    productPick.addEventListener('change', function () {
        var option = productPick.options[productPick.selectedIndex];
        if (!option || !option.dataset.category) {
            return;
        }
        var category = document.getElementById('category');
        var item = document.getElementById('item_name');
        var unit = document.getElementById('unit');
        var qty = document.getElementById('quantity');
        if (category) {
            category.value = option.dataset.category;
        }
        if (item) {
            item.value = option.dataset.name || '';
        }
        if (unit) {
            unit.value = option.dataset.unit || '';
        }
        if (qty && option.dataset.moq) {
            qty.min = option.dataset.moq;
            var hint = document.getElementById('moq-hint');
            if (hint) {
                hint.textContent = 'Minimum order for this listing is ' + option.dataset.moq + ' ' + (option.dataset.unit || '') + '.';
            }
        }
    });
}

var chatApp = document.getElementById('chatApp');
if (chatApp) {
    var thread = document.getElementById('chatThread');
    var form = document.getElementById('chatForm');
    var box = document.getElementById('chatBody');
    var seen = new Set();
    var after = thread ? thread.getAttribute('data-after') || '0' : '0';
    var withId = chatApp.getAttribute('data-with');
    var csrf = document.querySelector('meta[name="csrf"]');
    var token = csrf ? csrf.getAttribute('content') : '';

    function appendMessage(message) {
        if (!thread || seen.has(String(message.id))) {
            return;
        }
        seen.add(String(message.id));
        var bubble = document.createElement('div');
        bubble.className = 'bubble' + (message.mine ? ' mine' : '');
        var text = document.createElement('p');
        text.textContent = message.body;
        var time = document.createElement('time');
        time.textContent = message.time || '';
        bubble.appendChild(text);
        bubble.appendChild(time);
        thread.appendChild(bubble);
        thread.scrollTop = thread.scrollHeight;
        after = String(message.id);
    }

    if (thread) {
        thread.querySelectorAll('[data-id]').forEach(function (node) {
            seen.add(node.getAttribute('data-id'));
        });
        thread.scrollTop = thread.scrollHeight;
    }

    function poll() {
        if (!withId) {
            return;
        }
        fetch('chat.php?with=' + encodeURIComponent(withId) + '&after=' + encodeURIComponent(after), {
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            if (!response.ok) {
                return null;
            }
            return response.json();
        }).then(function (data) {
            if (!data || !Array.isArray(data.messages)) {
                return;
            }
            data.messages.forEach(appendMessage);
        }).catch(function () {
            return null;
        });
    }

    if (withId) {
        window.setInterval(poll, 4000);
    }

    if (form && box) {
        form.addEventListener('submit', function (event) {
            var body = box.value.trim();
            if (body === '') {
                event.preventDefault();
                return;
            }
            if (!window.fetch || !withId) {
                return;
            }
            event.preventDefault();
            var payload = new FormData();
            payload.append('csrf', token);
            payload.append('with', withId);
            payload.append('body', body);
            fetch('chat.php', { method: 'POST', body: payload })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data && data.message) {
                        box.value = '';
                        appendMessage(data.message);
                    } else if (data && data.error) {
                        window.alert(data.error);
                    }
                })
                .catch(function () {
                    form.submit();
                });
        });
    }
}
