/*
 * Page « Boutique » d'Admin2 (Grav 2.0) — plugin simple-shop.
 *
 * Composant web chargé par Admin2 (GET /gpm/plugins/simple-shop/page-script) ;
 * Admin2 définit window.__GRAV_PAGE_TAG (nom de la balise), ainsi que
 * window.__GRAV_API_SERVER_URL, __GRAV_API_PREFIX et __GRAV_API_TOKEN.
 *
 * Trois onglets, tous servis par l'API /simple-shop/* (ShopApiController) :
 *   - Commandes     : liste filtrable, détail, changement de statut + historique,
 *                     facture (statut e-reporting, PDF), export CSV comptable ;
 *   - Transporteurs : édition du YAML des modes de livraison (validé côté serveur) ;
 *   - Bannis        : liste des adresses e-mail bannies.
 *
 * Aucune donnée n'est insérée via innerHTML : tout passe par textContent
 * (pas d'injection possible depuis un nom de client ou un titre de produit).
 */
(function () {
    'use strict';

    var TAG = window.__GRAV_PAGE_TAG || 'grav-simple-shop--page';
    if (customElements.get(TAG)) {
        return;
    }

    var PER_PAGE = 25;

    // ------------------------------------------------------------------
    // Utilitaires
    // ------------------------------------------------------------------

    /** Construit un élément : h('div', {class: 'x', onclick: fn}, enfants…). */
    function h(tag, attrs) {
        var el = document.createElement(tag);
        var children = Array.prototype.slice.call(arguments, 2);

        Object.keys(attrs || {}).forEach(function (key) {
            var value = attrs[key];
            if (value === null || value === undefined || value === false) {
                return;
            }
            if (key.indexOf('on') === 0 && typeof value === 'function') {
                el.addEventListener(key.slice(2), value);
            } else if (key === 'value') {
                el.value = value;
            } else if (value === true) {
                el.setAttribute(key, '');
            } else {
                el.setAttribute(key, String(value));
            }
        });

        children.forEach(function append(child) {
            if (Array.isArray(child)) {
                child.forEach(append);
            } else if (child !== null && child !== undefined && child !== false) {
                el.appendChild(typeof child === 'object' ? child : document.createTextNode(String(child)));
            }
        });

        return el;
    }

    function money(value) {
        return Number(value || 0).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' });
    }

    function dateTime(value) {
        if (!value) {
            return '';
        }
        var d = new Date(String(value).replace(' ', 'T') + 'Z');

        return isNaN(d.getTime()) ? String(value) : d.toLocaleString('fr-FR');
    }

    function apiUrl(path) {
        return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + '/simple-shop' + path;
    }

    /** Appel API ; retourne le JSON (ou le Blob si opts.blob). */
    function api(method, path, body, opts) {
        opts = opts || {};
        var headers = { 'Accept': 'application/json', 'X-API-Token': window.__GRAV_API_TOKEN || '' };
        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }

        return fetch(apiUrl(path), {
            method: method,
            headers: headers,
            body: body !== undefined ? JSON.stringify(body) : undefined,
            credentials: 'same-origin'
        }).then(function (response) {
            if (response.ok) {
                if (opts.blob) {
                    return response.blob();
                }

                return response.status === 204 ? {} : response.json();
            }

            return response.json().catch(function () { return {}; }).then(function (problem) {
                var message = response.status === 401
                    ? 'Session expirée : rechargez la page pour vous reconnecter.'
                    : (problem.detail || problem.title || ('Erreur ' + response.status));
                var details = [];
                Object.keys(problem.errors || {}).forEach(function (field) {
                    details = details.concat(problem.errors[field]);
                });
                var error = new Error(message);
                error.details = details;
                throw error;
            });
        });
    }

    function download(blob, filename) {
        var url = URL.createObjectURL(blob);
        var link = h('a', { href: url, download: filename });
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    var STYLE = [
        '.ss{font-size:.875rem;color:var(--foreground,#111827)}',
        // Couleurs d'état : fond ET texte toujours fixés ensemble (lisibles quel que soit le thème d'Admin2).
        '.ss{--ss-ok-bg:#dcfce7;--ss-ok-fg:#14532d;--ss-err-bg:#fee2e2;--ss-err-fg:#7f1d1d;--ss-warn-bg:#fef3c7;--ss-warn-fg:#78350f;'
            + '--ss-info-bg:#e0e7ff;--ss-info-fg:#1e1b4b;--ss-blue-bg:#dbeafe;--ss-blue-fg:#1e3a8a;--ss-n-bg:#f3f4f6;--ss-n-fg:#1f2937}',
        '.ss[data-ss-theme=dark]{--ss-ok-bg:#14532d;--ss-ok-fg:#dcfce7;--ss-err-bg:#7f1d1d;--ss-err-fg:#fee2e2;--ss-warn-bg:#78350f;--ss-warn-fg:#fef3c7;'
            + '--ss-info-bg:#312e81;--ss-info-fg:#e0e7ff;--ss-blue-bg:#1e3a8a;--ss-blue-fg:#dbeafe;--ss-n-bg:#374151;--ss-n-fg:#f3f4f6}',
        '.ss *{box-sizing:border-box}',
        '.ss-tabs{display:flex;gap:.25rem;border-bottom:1px solid var(--border,#e5e7eb);margin-bottom:1rem}',
        '.ss-tab{padding:.5rem .9rem;border:0;background:none;cursor:pointer;color:var(--muted-foreground,#6b7280);border-bottom:2px solid transparent;font:inherit}',
        '.ss-tab[aria-selected=true]{color:var(--foreground,#111827);border-bottom-color:var(--primary,#2563eb);font-weight:600}',
        '.ss-bar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;margin-bottom:1rem}',
        '.ss-field{display:flex;flex-direction:column;gap:.2rem;font-size:.75rem;color:var(--muted-foreground,#6b7280)}',
        '.ss input,.ss select,.ss textarea{font:inherit;padding:.4rem .55rem;border:1px solid var(--border,#d1d5db);border-radius:.375rem;background:var(--background,#fff);color:inherit}',
        '.ss button.ss-btn{font:inherit;padding:.4rem .8rem;border:1px solid var(--border,#d1d5db);border-radius:.375rem;background:var(--background,#fff);color:inherit;cursor:pointer}',
        '.ss button.ss-primary{background:var(--primary,#2563eb);border-color:var(--primary,#2563eb);color:var(--primary-foreground,#fff)}',
        '.ss button:disabled{opacity:.5;cursor:wait}',
        '.ss table{width:100%;border-collapse:collapse}',
        '.ss th{text-align:left;font-weight:600;color:var(--muted-foreground,#6b7280);padding:.45rem .6rem;border-bottom:1px solid var(--border,#e5e7eb)}',
        '.ss td{padding:.5rem .6rem;border-bottom:1px solid var(--border,#f3f4f6);vertical-align:top}',
        '.ss tr.ss-row{cursor:pointer}',
        '.ss tr.ss-row:hover{background:var(--accent,#f9fafb)}',
        '.ss-num{text-align:right;white-space:nowrap}',
        '.ss-badge{display:inline-block;padding:.1rem .5rem;border-radius:999px;font-size:.75rem;background:var(--ss-info-bg);color:var(--ss-info-fg)}',
        '.ss-badge.paid{background:var(--ss-blue-bg);color:var(--ss-blue-fg)}.ss-badge.processing{background:var(--ss-warn-bg);color:var(--ss-warn-fg)}',
        '.ss-badge.shipped,.ss-badge.delivered{background:var(--ss-ok-bg);color:var(--ss-ok-fg)}',
        '.ss-badge.cancelled,.ss-badge.refunded{background:var(--ss-err-bg);color:var(--ss-err-fg)}',
        '.ss-badge.pending{background:var(--ss-n-bg);color:var(--ss-n-fg)}',
        '.ss-card{border:1px solid var(--border,#e5e7eb);border-radius:.5rem;padding:1rem;margin-top:1rem;background:var(--card,var(--background,#fff))}',
        '.ss-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr));gap:1rem}',
        '.ss h3{margin:0 0 .5rem;font-size:.95rem}',
        '.ss-msg{padding:.6rem .8rem;border-radius:.375rem;margin-bottom:.8rem}',
        '.ss-msg.ok{background:var(--ss-ok-bg);color:var(--ss-ok-fg)}.ss-msg.err{background:var(--ss-err-bg);color:var(--ss-err-fg)}',
        '.ss-msg.info{background:var(--ss-info-bg);color:var(--ss-info-fg)}',
        '.ss-muted{color:var(--muted-foreground,#6b7280)}',
        '.ss textarea.ss-yaml{width:100%;min-height:26rem;font-family:ui-monospace,Menlo,monospace;font-size:.8rem}',
        '.ss ul.ss-list{margin:0;padding-left:1.1rem}',
        '.ss-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}',
        '.ss :focus-visible{outline:2px solid var(--primary,#2563eb);outline-offset:2px}',
        '.ss-section{margin-top:1.5rem}.ss-section:first-of-type{margin-top:.5rem}',
        '.ss-provider{padding:.5rem 0;border-bottom:1px solid var(--border,#e5e7eb)}',
        '.ss-ok,.ss-ko{display:inline-block;padding:.1rem .5rem;border-radius:.375rem}',
        '.ss-ok{background:var(--ss-ok-bg);color:var(--ss-ok-fg)}.ss-ko{background:var(--ss-err-bg);color:var(--ss-err-fg)}',
        '.ss details.ss-mode,.ss details.ss-advanced{border:1px solid var(--border,#e5e7eb);border-radius:.5rem;margin:.6rem 0;background:var(--card,var(--background,#fff))}',
        '.ss details>summary{cursor:pointer;padding:.7rem .9rem;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center}',
        '.ss-mode-name{font-weight:600}',
        '.ss-badge.ss-b-on{background:var(--ss-ok-bg);color:var(--ss-ok-fg)}.ss-badge.ss-b-off{background:var(--ss-n-bg);color:var(--ss-n-fg)}',
        '.ss-badge.ss-b-ko{background:var(--ss-err-bg);color:var(--ss-err-fg)}',
        '.ss-done{display:block;padding:.4rem .6rem;border-radius:.375rem;background:var(--ss-ok-bg);color:var(--ss-ok-fg)}',
        '.ss-done:empty{display:none}',
        '.ss-mode-form{padding:0 .9rem .9rem}',
        '.ss-mode-form fieldset{border:1px solid var(--border,#e5e7eb);border-radius:.375rem;margin:.8rem 0;padding:.6rem .8rem}',
        '.ss-mode-form legend{font-weight:600;padding:0 .3rem}','.ss-mode-form fieldset{min-width:0}',
        '.ss-field-row{display:flex;flex-direction:column;gap:.2rem;margin:.4rem 0}',
        '.ss-field-row label{font-size:.8rem}',
        '.ss-field-row.ss-check{flex-direction:row;align-items:center;gap:.5rem}',
        '.ss-inline{display:flex;flex-wrap:wrap;gap:1rem}',
        '.ss-table-scroll{overflow-x:auto;margin:.5rem 0}',
        '.ss-table-scroll table input,.ss-table-scroll table select{width:7.5rem}',
        '.ss-new-mode{margin-top:1.2rem}.ss-new-mode h4{margin:0 0 .4rem}',
        '.ss-form-status{min-height:1.2rem;margin:.4rem 0 0}',
        '.ss-advanced>*:not(summary){padding:0 .9rem .9rem}',
        '.ss-hide-adv .ss-adv-col{display:none}',
        '.ss [hidden]{display:none !important}',
        '.ss details.ss-grid-details{border:1px solid var(--border,#e5e7eb);border-radius:.375rem;margin:.4rem 0 .8rem}',
        '.ss details.ss-grid-details>*:not(summary){margin-left:.9rem;margin-right:.9rem}',
        '.ss-choice label{font-size:.875rem}',
        '.ss-pricing-body{margin:.2rem 0 .6rem}',
        '.ss-preset{margin:.6rem 0 1rem}.ss-preset h4{margin:0 0 .4rem}',
        '@media (prefers-reduced-motion:no-preference){.ss details{transition:none}}'
    ].join('\n');

    // ------------------------------------------------------------------
    // Composant
    // ------------------------------------------------------------------

    class SimpleShopPage extends HTMLElement {
        connectedCallback() {
            this.tab = 'orders';
            this.filters = { status: '', q: '', date_from: '', date_to: '' };
            this.page = 1;
            this.root = h('div', { class: 'ss' });
            this.innerHTML = '';
            this.appendChild(h('style', {}, STYLE));
            this.appendChild(this.root);
            this.watchTheme();
            this.draw();
        }

        disconnectedCallback() {
            if (this.themeObserver) {
                this.themeObserver.disconnect();
            }
        }

        /**
         * Admin2 peut être en thème clair ou sombre : on lit la couleur du texte
         * héritée (claire = thème sombre) et on bascule nos couleurs d'état.
         * Ré-évalué quand <html>/<body> changent de classe ou d'attribut de thème.
         */
        watchTheme() {
            var self = this;
            function sync() {
                var m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(getComputedStyle(self).color || '');
                var dark = m ? (0.2126 * m[1] + 0.7152 * m[2] + 0.0722 * m[3]) / 255 > 0.5 : false;
                self.root.setAttribute('data-ss-theme', dark ? 'dark' : 'light');
            }
            sync();
            if (window.MutationObserver) {
                this.themeObserver = new MutationObserver(function () { window.requestAnimationFrame(sync); });
                [document.documentElement, document.body].forEach(function (node) {
                    self.themeObserver.observe(node, { attributes: true, attributeFilter: ['class', 'style', 'data-theme', 'data-mode'] });
                });
            }
            if (window.matchMedia) {
                var mq = window.matchMedia('(prefers-color-scheme: dark)');
                if (mq.addEventListener) { mq.addEventListener('change', function () { window.requestAnimationFrame(sync); }); }
            }
        }

        /** Message d'information / d'erreur en tête de page. */
        notify(text, isError, details) {
            this.message = text ? { text: text, error: !!isError, details: details || [] } : null;
            this.drawMessage();
        }

        drawMessage() {
            if (!this.messageBox) {
                return;
            }
            this.messageBox.innerHTML = '';
            if (this.message) {
                this.messageBox.appendChild(h('div', { class: 'ss-msg ' + (this.message.error ? 'err' : 'ok'), role: this.message.error ? 'alert' : 'status' },
                    this.message.text,
                    this.message.details.length ? h('ul', { class: 'ss-list' }, this.message.details.map(function (d) { return h('li', {}, d); })) : null));
            }
        }

        fail(error) {
            this.notify(error.message, true, error.details);
        }

        draw() {
            var self = this;
            var tabs = [['orders', 'Commandes'], ['carriers', 'Transporteurs'], ['bans', 'Adresses bannies']];

            this.root.innerHTML = '';
            this.messageBox = h('div');
            this.body = h('div', { id: 'ss-panel', role: 'tabpanel' });

            this.root.appendChild(h('div', { class: 'ss-tabs', role: 'tablist', 'aria-label': 'Sections de la boutique' }, tabs.map(function (t, i) {
                var selected = self.tab === t[0];
                return h('button', {
                    class: 'ss-tab', role: 'tab', type: 'button', id: 'ss-tab-' + t[0], 'aria-controls': 'ss-panel',
                    'aria-selected': selected ? 'true' : 'false', tabindex: selected ? '0' : '-1',
                    onclick: function () { self.tab = t[0]; self.message = null; self.draw(); },
                    onkeydown: function (ev) {
                        var target = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[ev.key];
                        if (target === undefined) { return; }
                        ev.preventDefault();
                        self.tab = tabs[(target + tabs.length) % tabs.length][0];
                        self.message = null;
                        self.draw();
                        var next = self.root.querySelector('#ss-tab-' + self.tab);
                        if (next) { next.focus(); }
                    }
                }, t[1]);
            })));
            this.root.appendChild(this.messageBox);
            this.root.appendChild(this.body);
            this.drawMessage();

            if (this.tab === 'orders') { this.showOrders(); }
            if (this.tab === 'carriers') { this.showCarriers(); }
            if (this.tab === 'bans') { this.showBans(); }
        }

        // ---------------- Commandes ----------------

        queryString(extra) {
            var params = [];
            var self = this;
            Object.keys(this.filters).forEach(function (k) {
                if (self.filters[k]) { params.push(k + '=' + encodeURIComponent(self.filters[k])); }
            });
            Object.keys(extra || {}).forEach(function (k) { params.push(k + '=' + encodeURIComponent(extra[k])); });

            return params.length ? '?' + params.join('&') : '';
        }

        showOrders() {
            var self = this;
            var f = this.filters;
            var statuses = { paid: 'Payée', processing: 'En préparation', shipped: 'Expédiée', delivered: 'Livrée', cancelled: 'Annulée', refunded: 'Remboursée' };

            var statusSelect = h('select', { id: 'ss-f-status' }, h('option', { value: '' }, 'Tous les statuts'),
                Object.keys(statuses).map(function (k) { return h('option', { value: k, selected: f.status === k }, statuses[k]); }));
            var search = h('input', { type: 'search', placeholder: 'Référence, nom, e-mail, téléphone', value: f.q, id: 'ss-f-q' });
            var from = h('input', { type: 'date', value: f.date_from, id: 'ss-f-from' });
            var to = h('input', { type: 'date', value: f.date_to, id: 'ss-f-to' });

            function apply() {
                f.status = statusSelect.value; f.q = search.value.trim(); f.date_from = from.value; f.date_to = to.value;
                self.page = 1;
                self.loadOrders();
            }

            var exportBtn = h('button', {
                class: 'ss-btn', type: 'button',
                onclick: function () {
                    exportBtn.disabled = true;
                    api('GET', '/orders/export' + self.queryString(), undefined, { blob: true })
                        .then(function (blob) { download(blob, 'commandes-' + new Date().toISOString().slice(0, 10) + '.csv'); })
                        .catch(function (e) { self.fail(e); })
                        .then(function () { exportBtn.disabled = false; });
                }
            }, 'Exporter en CSV');

            this.body.appendChild(h('form', { class: 'ss-bar', onsubmit: function (e) { e.preventDefault(); apply(); } },
                h('label', { class: 'ss-field', for: 'ss-f-status' }, 'Statut', statusSelect),
                h('label', { class: 'ss-field', for: 'ss-f-q' }, 'Recherche', search),
                h('label', { class: 'ss-field', for: 'ss-f-from' }, 'Du', from),
                h('label', { class: 'ss-field', for: 'ss-f-to' }, 'Au', to),
                h('button', { class: 'ss-btn ss-primary', type: 'submit' }, 'Filtrer'),
                exportBtn));

            this.list = h('div');
            this.detail = h('div');
            this.body.appendChild(this.list);
            this.body.appendChild(this.detail);
            this.loadOrders();
        }

        loadOrders() {
            var self = this;
            this.list.innerHTML = '';
            this.list.appendChild(h('p', { class: 'ss-muted' }, 'Chargement…'));

            api('GET', '/orders' + this.queryString({ page: this.page, per_page: PER_PAGE })).then(function (res) {
                var rows = res.data || [];
                var meta = res.meta || { total: rows.length, page: 1, per_page: PER_PAGE };
                var pages = Math.max(1, Math.ceil(meta.total / meta.per_page));

                self.list.innerHTML = '';
                if (!rows.length) {
                    self.list.appendChild(h('p', { class: 'ss-muted' }, 'Aucune commande ne correspond.'));
                    return;
                }

                self.list.appendChild(h('table', {},
                    h('thead', {}, h('tr', {}, ['Référence', 'Date', 'Client', 'Total', 'Statut'].map(function (c, i) {
                        return h('th', { class: i === 3 ? 'ss-num' : '' }, c);
                    }))),
                    h('tbody', {}, rows.map(function (o) {
                        return h('tr', { class: 'ss-row', tabindex: 0, onclick: function () { self.openOrder(o.order_reference); },
                            onkeydown: function (e) { if (e.key === 'Enter') { self.openOrder(o.order_reference); } } },
                            h('td', {}, o.order_reference),
                            h('td', {}, dateTime(o.created_at)),
                            h('td', {}, o.customer_name, h('div', { class: 'ss-muted' }, o.customer_email)),
                            h('td', { class: 'ss-num' }, money(o.total_amount)),
                            h('td', {}, h('span', { class: 'ss-badge ' + o.status }, o.status_label)));
                    }))));

                self.list.appendChild(h('div', { class: 'ss-bar', style: 'margin-top:.8rem;align-items:center' },
                    h('button', { class: 'ss-btn', type: 'button', disabled: self.page <= 1, onclick: function () { self.page--; self.loadOrders(); } }, 'Précédent'),
                    h('span', { class: 'ss-muted' }, 'Page ' + meta.page + ' / ' + pages + ' — ' + meta.total + ' commande(s)'),
                    h('button', { class: 'ss-btn', type: 'button', disabled: self.page >= pages, onclick: function () { self.page++; self.loadOrders(); } }, 'Suivant')));
            }).catch(function (e) { self.list.innerHTML = ''; self.fail(e); });
        }

        openOrder(reference) {
            var self = this;
            this.detail.innerHTML = '';
            this.detail.appendChild(h('p', { class: 'ss-muted' }, 'Chargement de ' + reference + '…'));

            api('GET', '/orders/' + encodeURIComponent(reference)).then(function (res) {
                self.renderOrder(res.data);
                self.detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }).catch(function (e) { self.detail.innerHTML = ''; self.fail(e); });
        }

        renderOrder(o) {
            var self = this;
            var c = o.customer || {};
            var ship = o.shipping || {};
            var b = o.billing || {};

            function address(a) {
                return [a.name, a.street, a['address-complement'], ((a['postal-code'] || '') + ' ' + (a.city || '')).trim()]
                    .filter(Boolean).map(function (line) { return h('div', {}, line); });
            }

            var statusSelect = h('select', { id: 'ss-new-status' }, Object.keys(o.statuses).map(function (k) {
                return h('option', { value: k, selected: k === o.status }, o.statuses[k]);
            }));
            var statusBtn = h('button', { class: 'ss-btn ss-primary', type: 'button', onclick: function () {
                statusBtn.disabled = true;
                api('PATCH', '/orders/' + encodeURIComponent(o.order_reference) + '/status', { status: statusSelect.value })
                    .then(function (res) {
                        self.notify('Statut de ' + o.order_reference + ' : ' + res.data.status_label + '.', false);
                        self.loadOrders();
                        self.openOrder(o.order_reference);
                    })
                    .catch(function (e) { self.fail(e); })
                    .then(function () { statusBtn.disabled = false; });
            } }, 'Enregistrer le statut');

            var inv = o.invoice || {};
            var invOptions = inv.ereporting_options || {};
            var invSelect = h('select', { id: 'ss-inv-status' }, Object.keys(invOptions).map(function (k) {
                return h('option', { value: k, selected: k === inv.ereporting_status }, invOptions[k]);
            }));
            var invBtn = h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                invBtn.disabled = true;
                api('PATCH', '/orders/' + encodeURIComponent(o.order_reference) + '/invoice-status', { status: invSelect.value })
                    .then(function (res) { self.notify('E-reporting : ' + res.data.ereporting_label + '.', false); self.openOrder(o.order_reference); })
                    .catch(function (e) { self.fail(e); })
                    .then(function () { invBtn.disabled = false; });
            } }, 'Enregistrer');
            var pdfBtn = h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                pdfBtn.disabled = true;
                api('GET', '/orders/' + encodeURIComponent(o.order_reference) + '/invoice', undefined, { blob: true })
                    .then(function (blob) { download(blob, (inv.number || o.order_reference) + '.pdf'); })
                    .catch(function (e) { self.fail(e); })
                    .then(function () { pdfBtn.disabled = false; });
            } }, 'Télécharger la facture (PDF)');

            var tr = o.tracking || {};
            var trInput = h('input', { type: 'text', id: 'ss-tracking', value: tr.number || '', maxlength: '40', placeholder: 'ex. 6A12345678901' });
            var trBtn = h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                trBtn.disabled = true;
                api('PATCH', '/orders/' + encodeURIComponent(o.order_reference) + '/tracking', { tracking_number: trInput.value })
                    .then(function (res) { self.notify(res.data.number ? 'Numéro de suivi enregistré.' : 'Numéro de suivi effacé.', false); self.openOrder(o.order_reference); })
                    .catch(function (e) { self.fail(e); })
                    .then(function () { trBtn.disabled = false; });
            } }, 'Enregistrer le suivi');

            this.detail.innerHTML = '';
            this.detail.appendChild(h('div', { class: 'ss-card' },
                h('h3', {}, 'Commande ' + o.order_reference + ' — ' + dateTime(o.created_at)),
                h('div', { class: 'ss-grid' },
                    h('div', {}, h('h3', {}, 'Client'),
                        h('div', {}, ((c.firstname || '') + ' ' + (c.lastname || '')).trim()),
                        h('div', {}, c.email), h('div', {}, c.tel),
                        h('div', { style: 'margin-top:.5rem' }, address(c))),
                    h('div', {}, h('h3', {}, 'Facturation'), address(b).length ? address(b) : h('span', { class: 'ss-muted' }, 'Identique à la livraison')),
                    h('div', {}, h('h3', {}, 'Livraison'), h('div', {}, ship.name || ship.key || ''), h('div', {}, money(ship.price)))),

                h('h3', { style: 'margin-top:1rem' }, 'Articles'),
                h('table', {}, h('tbody', {}, o.items.map(function (i) {
                    return h('tr', {}, h('td', {}, i.title), h('td', { class: 'ss-num' }, i.quantity + ' × ' + money(i.price)),
                        h('td', { class: 'ss-num' }, money(i.quantity * i.price)));
                }).concat([h('tr', {}, h('td', {}, h('strong', {}, 'Total TTC (livraison comprise)')), h('td'), h('td', { class: 'ss-num' }, h('strong', {}, money(o.total_amount))))]))),

                h('div', { class: 'ss-grid', style: 'margin-top:1rem' },
                    h('div', {}, h('h3', {}, 'Statut'),
                        h('div', { class: 'ss-bar' }, h('label', { class: 'ss-field', for: 'ss-new-status' }, 'Nouveau statut', statusSelect), statusBtn),
                        h('p', { class: 'ss-muted' }, 'Un e-mail est envoyé au client pour : expédiée, annulée, remboursée.'),
                        h('h3', { style: 'margin-top:1rem' }, 'Suivi du colis'),
                        h('div', { class: 'ss-bar' }, h('label', { class: 'ss-field', for: 'ss-tracking' }, 'Numéro de suivi', trInput), trBtn),
                        tr.url ? h('p', {}, h('a', { href: tr.url, target: '_blank', rel: 'noopener noreferrer' }, 'Page de suivi')) : null,
                        h('p', { class: 'ss-muted' }, 'Saisissez le numéro AVANT de passer la commande en « Expédiée » : il figure dans l’e-mail au client.')),
                    h('div', {}, h('h3', {}, 'Historique'),
                        h('ul', { class: 'ss-list' }, o.history.map(function (e) {
                            return h('li', {}, dateTime(e.changed_at) + ' — ' + e.new_status_label + (e.changed_by ? ' (par ' + e.changed_by + ')' : ' (automatique)'));
                        }))),
                    h('div', {}, h('h3', {}, 'Facture ' + (inv.number || '')),
                        h('p', {}, 'E-reporting : ', h('strong', {}, inv.ereporting_label || '—')),
                        h('div', { class: 'ss-bar' }, h('label', { class: 'ss-field', for: 'ss-inv-status' }, 'Statut e-reporting', invSelect), invBtn),
                        pdfBtn))));
        }

        // ---------------- Transporteurs ----------------

        showCarriers() {
            var self = this;
            var data = null;
            var modesBox = h('div');
            var providersBox = h('div');
            var infoBox = h('div', { role: 'note' });
            var simBox = h('div');
            var yamlBox = h('div');

            function section(id, title, intro) {
                return h('section', { class: 'ss-section', 'aria-labelledby': id },
                    h('h3', { id: id }, title), intro ? h('p', { class: 'ss-muted' }, intro) : null);
            }

            var sProviders = section('ss-s-providers', 'Transporteurs connectés', 'Plugins transporteur installés. Leurs identifiants (clés d’API) se saisissent dans la page du plugin.');
            var sModes = section('ss-s-modes', 'Modes de livraison', 'Pour chaque mode, choisissez un forfait, le tarif du transporteur ou votre propre grille. Les prix sont saisis hors taxe : la TVA est ajoutée automatiquement, puis le plancher légal.');
            var sSim = section('ss-s-sim', 'Simulateur', 'Vérifiez le prix que verrait un client pour un colis donné.');
            var sYaml = h('details', { class: 'ss-advanced' }, h('summary', {}, 'Mode avancé : fichier YAML'));

            var modesStatus = h('p', { class: 'ss-done', role: 'status', tabindex: '-1' });
            var editors = {};
            var simRefresh = null;
            var draftHolder = h('div');

            sProviders.appendChild(providersBox);
            sModes.appendChild(modesStatus);
            sModes.appendChild(modesBox);
            sSim.appendChild(simBox);
            sYaml.appendChild(yamlBox);

            this.body.appendChild(infoBox);
            this.body.appendChild(sProviders);
            this.body.appendChild(sModes);
            this.body.appendChild(sSim);
            this.body.appendChild(sYaml);

            /**
             * Recharge les données. after = { key, message } : rouvre le mode « key »,
             * y affiche le message et y place le focus ; sans « key » (mode supprimé),
             * le message s'affiche en tête de la liste des modes, qui reçoit le focus.
             * Le simulateur garde sa saisie et recalcule s'il avait déjà un résultat.
             */
            function reload(after) {
                return api('GET', '/carriers').then(function (res) {
                    data = res.data;
                    drawInfo();
                    drawProviders();
                    drawModes();
                    if (simRefresh) { simRefresh(); } else { drawSimulator(); }
                    drawYaml();
                    if (after && after.message) {
                        var ed = after.key ? editors[after.key] : null;
                        if (ed) {
                            ed.done(after.message);
                        } else {
                            modesStatus.textContent = after.message;
                            modesStatus.focus();
                        }
                    }
                }).catch(function (e) { self.fail(e); });
            }

            // ----- Encart : TVA, emballage, plancher légal -----
            function drawInfo() {
                var law = (data.legal || {}).book_law || {};
                var pct = ((data.legal || {}).vat_rate || 0) * 100;
                var pack = (data.settings || {}).packaging || {};
                var ht = (data.settings || {}).prices_entered === 'ht';
                infoBox.innerHTML = '';
                infoBox.appendChild(h('div', { class: 'ss-msg ok' },
                    h('p', { style: 'margin:0 0 .4rem' }, h('strong', {}, 'Plancher légal (loi Darcos) : '),
                        law.enabled
                            ? 'les frais de livraison de livres ne peuvent pas être inférieurs à ' + money(law.below) + ' TTC pour une commande de livres de moins de '
                                + money(law.threshold) + ' TTC, ni à ' + money(law.at_or_above) + ' TTC au-delà. Un mode peut y échapper (retrait sur place).'
                            : 'désactivé.'),
                    h('p', { style: 'margin:0' }, 'Prix saisis ' + (ht ? 'hors taxe, TVA ' : 'toutes taxes comprises (TVA ')
                        + pct.toLocaleString('fr-FR') + ' %' + (ht ? ' ajoutée automatiquement' : ')') + '. Emballage ajouté à chaque colis : '
                        + pack.weight + ' g et ' + pack.thickness + ' mm. Ces réglages, le seuil et les montants du plancher : Plugins > Simple Shop > Livraison.')));
            }

            // ----- Fournisseurs -----
            function drawProviders() {
                providersBox.innerHTML = '';
                if (!data.providers.length) {
                    providersBox.appendChild(h('p', { class: 'ss-muted' }, 'Aucun plugin transporteur actif. Seuls les modes manuels ci-dessous sont proposés.'));
                    return;
                }
                data.providers.forEach(function (p) {
                    var status = h('span', { role: 'status', class: 'ss-muted' });
                    var btn = h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                        btn.disabled = true;
                        status.textContent = 'Test en cours…';
                        api('POST', '/carriers/providers/' + encodeURIComponent(p.id) + '/test', {})
                            .then(function (res) { status.textContent = (res.data.ok ? 'Réussi : ' : 'Échec : ') + res.data.message; status.className = res.data.ok ? 'ss-ok' : 'ss-ko'; })
                            .catch(function (e) { status.textContent = 'Échec : ' + e.message; status.className = 'ss-ko'; })
                            .then(function () { btn.disabled = false; });
                    } }, 'Tester la connexion');
                    providersBox.appendChild(h('div', { class: 'ss-provider' },
                        h('strong', {}, p.label), ' ', h('span', { class: 'ss-muted' }, '(plugin « ' + p.id + ' »)'), ' ', btn, ' ', status));
                });
            }

            // ----- Modes -----
            function drawModes() {
                modesBox.innerHTML = '';
                modesStatus.textContent = '';
                editors = {};
                draftHolder.innerHTML = '';
                var preset = presetCard();
                if (preset) { modesBox.appendChild(preset); }
                data.modes.forEach(function (m) {
                    var ed = modeEditor(m, false);
                    editors[m.key] = ed;
                    modesBox.appendChild(ed.el);
                });
                modesBox.appendChild(draftHolder);
                modesBox.appendChild(newModeForm());
            }

            function sourceLabel(m) {
                if (m.source === 'manual') { return 'Manuel'; }
                var label = 'Plugin ' + m.provider;
                return m.source === 'provider+override' ? label + ' — modifié' : label;
            }

            function statusLabel(m) {
                if (!m.available) { return 'Indisponible : ' + (m.unavailable_reason || 'réglages du plugin incomplets'); }
                return m.enabled ? 'Actif' : 'Désactivé';
            }

            function field(label, input, help) {
                var id = input.id;
                return h('div', { class: 'ss-field-row' },
                    h('label', { for: id }, label), input,
                    help ? h('small', { id: id + '-help', class: 'ss-muted' }, help) : null);
            }

            var uid = 0;
            function nextId(prefix) { uid++; return prefix + '-' + uid; }

            function numInput(value, opts) {
                opts = opts || {};
                return h('input', {
                    type: 'number', id: nextId('ss-n'), min: '0', step: opts.step || 'any', inputmode: 'decimal',
                    value: value === null || value === undefined ? '' : String(value), placeholder: opts.placeholder || '',
                    'aria-label': opts.aria || null, 'aria-describedby': opts.describedby || null
                });
            }

            var PRICING_LABELS = { flat: 'Forfait', provider: 'Tarif du plugin', grid: 'Grille personnalisée' };
            var CSV_COLUMNS = ['zone', 'poids_de_g', 'poids_jusqua_g', 'panier_de_eur', 'panier_jusqua_eur', 'epaisseur_max_mm', 'prix_eur'];
            var WEIGHT_INFINITE = 9999999, BASKET_INFINITE = 99999;

            function isHt() { return (data.settings || {}).prices_entered === 'ht'; }
            function taxLabel() { return isHt() ? 'HT' : 'TTC'; }

            /** « 33 lignes, de 6,72 € à 36,39 € HT, jusqu'à 30 000 g » */
            function gridSummary(rules) {
                if (!rules.length) { return 'Grille vide.'; }
                var prices = rules.map(function (r) { return parseFloat(r.price); }).filter(function (p) { return !isNaN(p); });
                var maxW = 0, unlimited = false;
                rules.forEach(function (r) {
                    var w = r.max_weight === null || r.max_weight === '' || r.max_weight === undefined ? Infinity : parseFloat(r.max_weight);
                    if (w >= WEIGHT_INFINITE) { unlimited = true; } else if (w > maxW) { maxW = w; }
                });
                var text = rules.length + (rules.length > 1 ? ' lignes' : ' ligne');
                if (prices.length) {
                    var lo = Math.min.apply(null, prices), hi = Math.max.apply(null, prices);
                    text += lo === hi ? ', ' + money(lo) + ' ' + taxLabel() : ', de ' + money(lo) + ' à ' + money(hi) + ' ' + taxLabel();
                }
                text += unlimited ? ', sans limite de poids' : (maxW ? ', jusqu’à ' + maxW.toLocaleString('fr-FR') + ' g' : '');

                return text + '.';
            }

            function usesAdvancedColumns(rules) {
                return rules.some(function (r) {
                    return (parseFloat(r.min_basket) || 0) > 0
                        || (r.max_basket !== null && r.max_basket !== undefined && r.max_basket !== '' && parseFloat(r.max_basket) < BASKET_INFINITE)
                        || (r.max_thickness !== null && r.max_thickness !== undefined && r.max_thickness !== '' && parseFloat(r.max_thickness) > 0);
                });
            }

            /** Texte CSV (séparateur « ; », virgule décimale, en-tête) d'une liste de lignes de grille. */
            function rulesToCsv(rules) {
                function num(v, infinite) {
                    if (v === null || v === undefined || v === '' || (infinite && parseFloat(v) >= infinite)) { return ''; }
                    return String(v).replace('.', ',');
                }
                var lines = [CSV_COLUMNS.slice(0, 6).concat(['prix_eur_' + taxLabel().toLowerCase()]).join(';')];
                rules.forEach(function (r) {
                    lines.push([r.zone, num(r.min_weight), num(r.max_weight, WEIGHT_INFINITE), num(r.min_basket), num(r.max_basket, BASKET_INFINITE),
                        num(r.max_thickness), num(r.price)].join(';'));
                });

                return '﻿' + lines.join('\r\n') + '\r\n';
            }

            /**
             * Lit un CSV de grille (colonnes dans l'ordre de l'export ; séparateur « ; », « , » ou tabulation ;
             * virgule ou point décimal ; ligne d'en-tête facultative).
             * @return {{rules: Array, errors: string[]}}
             */
            function csvToRules(text) {
                var lines = text.replace(/^﻿/, '').split(/\r?\n/).filter(function (l) { return l.trim() !== ''; });
                var errors = [], rules = [];
                if (!lines.length) { return { rules: [], errors: ['Le fichier est vide.'] }; }
                var first = lines[0];
                var delim = first.indexOf(';') > -1 ? ';' : (first.indexOf('\t') > -1 ? '\t' : ',');
                lines.forEach(function (line, i) {
                    var cells = line.split(delim).map(function (c) { return c.trim().replace(/^"|"$/g, '').trim(); });
                    if (i === 0 && data.all_zones.indexOf(cells[0]) === -1) { return; } // en-tête
                    var n = i + 1;
                    if (data.all_zones.indexOf(cells[0]) === -1) { errors.push('Ligne ' + n + ' : zone « ' + cells[0] + ' » inconnue (' + data.all_zones.join(', ') + ').'); return; }
                    function num(k, label, required) {
                        var raw = (cells[k] || '').replace(/\s/g, '').replace(',', '.');
                        if (raw === '') {
                            if (required) { errors.push('Ligne ' + n + ' : ' + label + ' manquant.'); }
                            return null;
                        }
                        if (isNaN(Number(raw)) || Number(raw) < 0) { errors.push('Ligne ' + n + ' : ' + label + ' invalide (« ' + cells[k] + ' »).'); return null; }
                        return Number(raw);
                    }
                    rules.push({
                        zone: cells[0], min_weight: num(1, 'poids de') || 0, max_weight: num(2, 'poids jusqu’à'),
                        min_basket: num(3, 'panier de') || 0, max_basket: num(4, 'panier jusqu’à'),
                        max_thickness: num(5, 'épaisseur'), price: num(6, 'prix', true)
                    });
                });
                if (!rules.length && !errors.length) { errors.push('Aucune ligne de tarif trouvée.'); }

                return { rules: rules, errors: errors };
            }

            function modeEditor(m, isNew) {
                var inputs = {};
                var uidp = nextId('ss-mode');
                var isProvider = !!m.provider;
                var pricing = m.pricing || (isProvider ? 'provider' : 'grid');

                var status = h('p', { class: 'ss-form-status', role: 'status', tabindex: '-1' });
                var errors = h('div', { role: 'alert' });

                inputs.enabled = h('input', { type: 'checkbox', id: uidp + '-enabled', checked: !!m.enabled });
                inputs.name = h('input', { type: 'text', id: uidp + '-name', value: m.name || '', required: true, maxlength: '80' });
                inputs.description = h('textarea', { id: uidp + '-desc', rows: '2', maxlength: '300' });
                inputs.description.value = m.description || '';
                inputs.flat = numInput(m.flat_price);
                inputs.flat.id = uidp + '-flat';
                inputs.mfixed = numInput(m.margin.fixed);
                inputs.mfixed.id = uidp + '-mfixed';
                inputs.mpercent = numInput(m.margin.percent);
                inputs.mpercent.id = uidp + '-mpercent';
                inputs.free = numInput(m.free_from);
                inputs.free.id = uidp + '-free';
                inputs.floor = h('input', { type: 'checkbox', id: uidp + '-floor', checked: m.legal_floor !== false });
                inputs.maxw = numInput(m.constraints.max_weight);
                inputs.maxw.id = uidp + '-maxw';
                inputs.maxt = numInput(m.constraints.max_thickness);
                inputs.maxt.id = uidp + '-maxt';

                // ----- Choix du calcul -----
                var choices = isProvider ? ['flat', 'provider', 'grid'] : ['flat', 'grid'];
                var hints = {
                    flat: 'Un seul prix, quel que soit le colis. Le plus simple.',
                    provider: 'La grille fournie par le plugin ' + m.provider + ', mise à jour avec le plugin. Rien à saisir.',
                    grid: 'Votre propre grille : prix selon le poids (et, si besoin, le montant du panier ou l’épaisseur).'
                };
                var radios = {};
                var pricingFieldset = h('fieldset', {}, h('legend', {}, 'Calcul du prix'),
                    choices.map(function (c) {
                        radios[c] = h('input', { type: 'radio', name: uidp + '-pricing', id: uidp + '-p-' + c, value: c, checked: c === pricing,
                            'aria-describedby': uidp + '-p-' + c + '-hint', onchange: function () { setPricing(c); } });
                        return h('div', { class: 'ss-field-row ss-check ss-choice' }, radios[c],
                            h('label', { for: uidp + '-p-' + c }, h('strong', {}, PRICING_LABELS[c]), ' ', h('span', { id: uidp + '-p-' + c + '-hint', class: 'ss-muted' }, hints[c])));
                    }));

                // ----- Forfait -----
                var law = (data.legal || {}).book_law || {};
                var flatBlock = h('div', {},
                    field('Prix du forfait (€ ' + taxLabel() + ')', inputs.flat,
                        law.enabled
                            ? '0 = le minimum légal : ' + money(law.below) + ' TTC sous ' + money(law.threshold) + ' de livres, ' + money(law.at_or_above) + ' au-delà.'
                            : null));

                // ----- Grille du plugin (lecture seule) -----
                var providerRules = pricing === 'provider' ? (m.rules || []) : null;
                var providerBlock = h('div', {});
                if (isProvider) {
                    var shownRules = providerRules || [];
                    var roTable = h('table', {},
                        h('caption', { class: 'ss-sr' }, 'Grille du plugin pour ' + (m.name || '')),
                        h('thead', {}, h('tr', {}, ['Zone', 'Poids de (g)', 'Jusqu’à (g)', 'Prix (€ ' + taxLabel() + ')'].map(function (t) { return h('th', { scope: 'col' }, t); }))),
                        h('tbody', {}, shownRules.map(function (r) {
                            return h('tr', {}, h('td', {}, r.zone), h('td', { class: 'ss-num' }, (r.min_weight || 0).toLocaleString('fr-FR')),
                                h('td', { class: 'ss-num' }, r.max_weight >= WEIGHT_INFINITE ? 'sans limite' : Number(r.max_weight).toLocaleString('fr-FR')),
                                h('td', { class: 'ss-num' }, money(r.price)));
                        })));
                    providerBlock.appendChild(h('p', {}, 'Grille du plugin : ' + gridSummary(shownRules)));
                    if (shownRules.length) {
                        providerBlock.appendChild(h('details', { class: 'ss-grid-details' }, h('summary', {}, 'Voir la grille du plugin'),
                            h('div', { class: 'ss-table-scroll' }, roTable),
                            h('div', { class: 'ss-bar' },
                                h('button', { class: 'ss-btn', type: 'button', onclick: function () { download(new Blob([rulesToCsv(shownRules)], { type: 'text/csv;charset=utf-8' }), m.key + '-grille.csv'); } }, 'Exporter en CSV'))));
                    }
                    providerBlock.appendChild(h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                        radios.grid.checked = true;
                        setPricing('grid');
                        gridDetails.open = true;
                        (rows[0] ? rows[0].price : addBtn).focus();
                    } }, 'Personnaliser cette grille'));
                }

                // ----- Grille personnalisée (modifiable) -----
                var zones = (data.zones && data.zones.length) ? data.zones.slice() : data.all_zones.slice();
                var tbody = h('tbody');
                var rows = [];
                var gridSummaryText = h('p', {});

                function cell(input, advanced) { return h('td', { class: advanced ? 'ss-adv-col' : null }, input); }

                function addRow(rule) {
                    rule = rule || { zone: zones[0], min_weight: 0, max_weight: null, min_basket: 0, max_basket: null, price: '' };
                    var n = rows.length + 1;
                    var zoneList = zones.indexOf(rule.zone) === -1 ? zones.concat([rule.zone]) : zones;
                    var r = {
                        zone: h('select', { 'aria-label': 'Zone, ligne ' + n }, zoneList.map(function (z) { return h('option', { value: z, selected: z === rule.zone }, z); })),
                        minw: numInput(rule.min_weight, { aria: 'Poids de (g), ligne ' + n }),
                        maxw: numInput(rule.max_weight !== null && rule.max_weight >= WEIGHT_INFINITE ? null : rule.max_weight, { aria: 'Poids jusqu’à (g), ligne ' + n, placeholder: 'sans limite' }),
                        minb: numInput(rule.min_basket, { aria: 'Panier de (€), ligne ' + n }),
                        maxb: numInput(rule.max_basket !== null && rule.max_basket >= BASKET_INFINITE ? null : rule.max_basket, { aria: 'Panier jusqu’à (€), ligne ' + n, placeholder: 'sans limite' }),
                        thick: numInput(rule.max_thickness, { aria: 'Épaisseur maximale (mm), ligne ' + n, placeholder: 'sans limite' }),
                        price: numInput(rule.price, { aria: 'Prix (€ ' + taxLabel() + '), ligne ' + n })
                    };
                    r.del = h('button', { class: 'ss-btn', type: 'button', 'aria-label': 'Supprimer la ligne ' + n, onclick: function () {
                        rows.splice(rows.indexOf(r), 1);
                        r.tr.remove();
                        refreshGridSummary();
                        status.textContent = 'Ligne supprimée (pas encore enregistré).';
                        (rows.length ? rows[0].zone : addBtn).focus();
                    } }, 'Supprimer');
                    r.tr = h('tr', {}, cell(r.zone), cell(r.minw), cell(r.maxw), cell(r.minb, true), cell(r.maxb, true), cell(r.thick, true), cell(r.price), cell(r.del));
                    rows.push(r);
                    tbody.appendChild(r.tr);

                    return r;
                }

                function v(i) { return i.value === '' ? null : i.value; }
                function currentRules() {
                    return rows.map(function (r) {
                        return { zone: r.zone.value, min_weight: v(r.minw), max_weight: v(r.maxw), min_basket: v(r.minb), max_basket: v(r.maxb), max_thickness: v(r.thick), price: v(r.price) };
                    });
                }
                function refreshGridSummary() {
                    gridSummaryText.textContent = 'Votre grille : ' + gridSummary(currentRules());
                    gridDetailsLabel.textContent = 'Modifier la grille (' + rows.length + (rows.length > 1 ? ' lignes)' : ' ligne)');
                }

                var addBtn = h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                    var r = addRow();
                    refreshGridSummary();
                    r.minw.focus();
                } }, 'Ajouter une ligne');

                (m.rules || []).forEach(addRow);

                var advToggle = h('input', { type: 'checkbox', id: uidp + '-adv', checked: usesAdvancedColumns(m.rules || []), onchange: function () {
                    table.classList.toggle('ss-hide-adv', !advToggle.checked);
                } });
                var table = h('table', { class: advToggle.checked ? null : 'ss-hide-adv' },
                    h('caption', { class: 'ss-sr' }, 'Grille de tarifs du mode ' + (m.name || 'nouveau')),
                    h('thead', {}, h('tr', {}, [['Zone'], ['Poids de (g)'], ['Jusqu’à (g)'], ['Panier de (€)', 1], ['Jusqu’à (€)', 1], ['Épaisseur max (mm)', 1], ['Prix (€ ' + taxLabel() + ')'], ['']].map(function (t) {
                        return h('th', { scope: 'col', class: t[1] ? 'ss-adv-col' : null }, t[0]);
                    }))),
                    tbody);

                var fileInput = h('input', { type: 'file', accept: '.csv,text/csv,text/plain', class: 'ss-sr', id: uidp + '-csv', tabindex: '-1', onchange: function () {
                    var file = fileInput.files && fileInput.files[0];
                    if (!file) { return; }
                    file.text().then(function (text) {
                        var parsed = csvToRules(text);
                        fileInput.value = '';
                        if (parsed.errors.length) {
                            showErrors({ message: 'Import impossible : le fichier n’a pas été pris en compte.', details: parsed.errors.slice(0, 20) });
                            return;
                        }
                        if (rows.length && !window.confirm('Remplacer les ' + rows.length + ' lignes actuelles par les ' + parsed.rules.length + ' lignes du fichier ?')) { return; }
                        rows.slice().forEach(function (r) { r.tr.remove(); });
                        rows.length = 0;
                        parsed.rules.forEach(addRow);
                        if (usesAdvancedColumns(parsed.rules) && !advToggle.checked) { advToggle.checked = true; table.classList.remove('ss-hide-adv'); }
                        refreshGridSummary();
                        errors.innerHTML = '';
                        status.className = 'ss-form-status';
                        status.textContent = parsed.rules.length + ' lignes importées. Vérifiez puis enregistrez le mode.';
                        status.focus();
                    });
                } });

                var gridDetailsLabel = h('span', {});
                var gridDetails = h('details', { class: 'ss-grid-details', open: isNew }, h('summary', {}, gridDetailsLabel),
                    h('p', { class: 'ss-muted' }, 'La première ligne qui correspond au colis s’applique. Poids en grammes ; laissez « jusqu’à » vide pour « sans limite ».'),
                    h('div', { class: 'ss-field-row ss-check' }, advToggle, h('label', { for: uidp + '-adv' }, 'Afficher les colonnes avancées (montant du panier, épaisseur)')),
                    h('div', { class: 'ss-table-scroll', role: 'region', tabindex: '0', 'aria-label': 'Grille de tarifs, défilement horizontal' }, table),
                    h('div', { class: 'ss-bar' }, addBtn,
                        h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                            download(new Blob([rulesToCsv(currentRules())], { type: 'text/csv;charset=utf-8' }), m.key + '-grille.csv');
                        } }, 'Exporter en CSV'),
                        h('button', { class: 'ss-btn', type: 'button', onclick: function () { fileInput.click(); } }, 'Importer un CSV…'),
                        fileInput),
                    h('p', { class: 'ss-muted' }, 'CSV : une ligne par tranche, colonnes ' + CSV_COLUMNS.join(' ; ') + ' (séparateur « ; », virgule décimale ; l’export donne un modèle).'));
                var gridBlock = h('div', {}, gridSummaryText, gridDetails);
                refreshGridSummary();

                var marginFieldset = h('fieldset', {}, h('legend', {}, 'Marge sur le tarif de la grille'),
                    h('div', { class: 'ss-inline' },
                        field('Marge fixe (€ ' + taxLabel() + ')', inputs.mfixed),
                        field('Marge (%)', inputs.mpercent)),
                    h('p', { class: 'ss-muted' }, 'Ajoutées au prix de la grille (ex. 0,50 € pour le suivi de la lettre verte). Pas de marge sur une ligne à 0 €.'));

                function setPricing(p) {
                    pricing = p;
                    flatBlock.hidden = p !== 'flat';
                    providerBlock.hidden = p !== 'provider';
                    gridBlock.hidden = p !== 'grid';
                    marginFieldset.hidden = p === 'flat';
                    if (p === 'grid' && !rows.length && isProvider) {
                        (m.provider_rules || m.rules || []).forEach(addRow);
                        refreshGridSummary();
                    }
                }

                function read() {
                    return {
                        name: inputs.name.value,
                        description: inputs.description.value,
                        enabled: inputs.enabled.checked,
                        legal_floor: inputs.floor.checked,
                        pricing: pricing,
                        flat_price: pricing === 'flat' ? v(inputs.flat) : null,
                        margin: { fixed: v(inputs.mfixed), percent: v(inputs.mpercent) },
                        free_from: v(inputs.free),
                        constraints: { max_weight: v(inputs.maxw), max_thickness: v(inputs.maxt) },
                        rules: pricing === 'grid' ? currentRules() : []
                    };
                }

                var saveBtn = h('button', { class: 'ss-btn ss-primary', type: 'submit' }, isNew ? 'Créer le mode' : 'Enregistrer ce mode');
                var extraBtn = null;
                if (!isNew && m.source === 'provider+override') {
                    extraBtn = h('button', { class: 'ss-btn', type: 'button', onclick: function () { remove('Rétablir les valeurs du plugin pour « ' + m.name + ' » ? Vos modifications de ce mode seront perdues.'); } }, 'Rétablir les valeurs du plugin');
                } else if (!isNew && m.source === 'manual') {
                    extraBtn = h('button', { class: 'ss-btn', type: 'button', onclick: function () { remove('Supprimer le mode « ' + m.name + ' » ?'); } }, 'Supprimer ce mode');
                }

                function remove(question) {
                    if (!window.confirm(question)) { return; }
                    api('DELETE', '/carriers/' + encodeURIComponent(m.key))
                        .then(function () {
                            return m.source === 'manual'
                                ? reload({ message: 'Mode « ' + m.name + ' » supprimé.' })
                                : reload({ key: m.key, message: 'Mode « ' + m.name + ' » : valeurs du plugin rétablies.' });
                        })
                        .catch(function (e) { showErrors(e); });
                }

                function showErrors(e) {
                    errors.innerHTML = '';
                    status.textContent = '';
                    errors.appendChild(h('div', { class: 'ss-msg err' }, e.message,
                        e.details && e.details.length ? h('ul', { class: 'ss-list' }, e.details.map(function (d) { return h('li', {}, d); })) : null));
                    errors.scrollIntoView({ block: 'nearest' });
                }

                var form = h('form', { class: 'ss-mode-form', onsubmit: function (ev) {
                    ev.preventDefault();
                    errors.innerHTML = '';
                    saveBtn.disabled = true;
                    status.className = 'ss-form-status';
                    status.textContent = 'Enregistrement…';
                    api('PUT', '/carriers/' + encodeURIComponent(m.key), read())
                        .then(function () { return reload({ key: m.key, message: 'Mode « ' + inputs.name.value + ' » ' + (isNew ? 'créé.' : 'enregistré.') }); })
                        .catch(function (e) { showErrors(e); })
                        .then(function () { saveBtn.disabled = false; });
                } },
                    h('div', { class: 'ss-field-row ss-check' }, inputs.enabled, h('label', { for: uidp + '-enabled' }, 'Proposer ce mode aux clients')),
                    m.available === false ? h('p', { class: 'ss-msg err' }, 'Indisponible pour le moment : ' + (m.unavailable_reason || 'réglages du plugin incomplets') + ' Le mode n’est pas proposé tant que ce n’est pas réglé.') : null,
                    field('Nom affiché au client', inputs.name),
                    field('Description affichée au client', inputs.description),
                    pricingFieldset,
                    h('div', { class: 'ss-pricing-body' }, flatBlock, providerBlock, gridBlock),
                    marginFieldset,
                    h('fieldset', {}, h('legend', {}, 'Règles'),
                        h('div', { class: 'ss-inline' },
                            field('Offert dès (€ de livres TTC)', inputs.free, 'Vide = jamais. Au-dessus, la livraison est offerte, au plancher légal près.'),
                            field('Poids maximal du colis (g)', inputs.maxw, 'Vide = illimité.'),
                            field('Épaisseur maximale (mm)', inputs.maxt, 'Vide = illimité.')),
                        h('div', { class: 'ss-field-row ss-check' }, inputs.floor,
                            h('label', { for: uidp + '-floor' }, 'Appliquer le plancher légal des frais de livraison de livres')),
                        h('p', { class: 'ss-muted' }, 'À décocher uniquement pour un retrait sur place, qui n’est pas une livraison.')),
                    errors,
                    h('div', { class: 'ss-bar' }, saveBtn, extraBtn),
                    status);

                setPricing(pricing);

                var pricingBadge = pricing === 'flat' && m.flat_price !== null && m.flat_price !== undefined
                    ? 'Forfait ' + money(m.flat_price) + ' ' + taxLabel()
                    : PRICING_LABELS[pricing];
                var summary = h('summary', {},
                    h('span', { class: 'ss-mode-name' }, m.name || 'Nouveau mode'), ' ',
                    h('span', { class: 'ss-badge' }, sourceLabel(m)), ' ',
                    isNew ? null : h('span', { class: 'ss-badge ss-b-off' }, pricingBadge), ' ',
                    h('span', { class: 'ss-badge ' + (!m.available ? 'ss-b-ko' : (m.enabled ? 'ss-b-on' : 'ss-b-off')) }, statusLabel(m)));
                var el = h('details', { class: 'ss-mode', open: isNew }, summary, form);

                return {
                    el: el,
                    focus: function () { inputs.name.focus(); },
                    /** Après un enregistrement : mode ouvert, message visible et focus dessus. */
                    done: function (message) {
                        el.open = true;
                        status.className = 'ss-form-status ss-done';
                        status.textContent = message;
                        status.focus();
                        status.scrollIntoView({ block: 'center' });
                    }
                };
            }

            // ----- Préréglage « Livres France » (proposé tant qu'il n'est ni appliqué ni écarté) -----
            function presetCard() {
                if (((data.setup || {}).books_preset || '') !== 'pending') { return null; }
                var law = (data.legal || {}).book_law || {};
                var disable = h('input', { type: 'checkbox', id: 'ss-preset-disable' });
                var cardStatus = h('div', { role: 'alert' });
                function send(action) {
                    cardStatus.innerHTML = '';
                    api('POST', '/carriers/preset', { action: action, disable_others: disable.checked })
                        .then(function (res) {
                            return action === 'apply'
                                ? reload({ key: res.data.key, message: 'Préréglage « Livres France » appliqué : mode « Livraison » créé.' + (disable.checked ? ' Les autres modes sont désactivés.' : '') })
                                : reload({ message: 'Préréglage écarté : il ne sera plus proposé.' });
                        })
                        .catch(function (e) {
                            cardStatus.appendChild(h('div', { class: 'ss-msg err' }, e.message,
                                e.details && e.details.length ? h('ul', { class: 'ss-list' }, e.details.map(function (d) { return h('li', {}, d); })) : null));
                        });
                }

                return h('section', { class: 'ss-card ss-preset', 'aria-labelledby': 'ss-preset-title' },
                    h('h4', { id: 'ss-preset-title' }, 'Préréglage proposé : « Livres France »'),
                    h('p', {}, law.enabled
                        ? 'Un seul mode, au minimum fixé par la loi : ' + money(law.below) + ' pour moins de ' + money(law.threshold) + ' de livres, '
                            + money(law.at_or_above) + ' au-delà, quel que soit le poids. C’est ce que pratiquent la plupart des librairies en ligne ; la différence avec le coût réel d’envoi est à votre charge.'
                        : 'Le plancher légal est désactivé (Plugins > Simple Shop > Livraison) : ce préréglage n’est pas applicable.'),
                    h('div', { class: 'ss-field-row ss-check' }, disable, h('label', { for: 'ss-preset-disable' }, 'Désactiver les autres modes (réactivables à tout moment)')),
                    cardStatus,
                    h('div', { class: 'ss-bar' },
                        h('button', { class: 'ss-btn ss-primary', type: 'button', disabled: !law.enabled, onclick: function () { send('apply'); } }, 'Appliquer le préréglage'),
                        h('button', { class: 'ss-btn', type: 'button', onclick: function () { send('dismiss'); } }, 'Non merci')));
            }

            function newModeForm() {
                var nameInput = h('input', { type: 'text', id: 'ss-new-mode-name', maxlength: '80' });
                var btn = h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                    var name = nameInput.value.trim();
                    if (!name) { nameInput.focus(); return; }
                    var base = slug(name), key = base, i = 2;
                    var existing = data.modes.map(function (x) { return x.key; });
                    while (existing.indexOf(key) !== -1) { key = base + '_' + (i++); }
                    var draft = { key: key, source: 'manual', provider: null, name: name, description: '', enabled: false, legal_floor: true,
                        pricing: 'flat', flat_price: null, margin: { fixed: 0, percent: 0 }, free_from: null,
                        constraints: { max_weight: null, max_thickness: null }, rules: [], available: true };
                    var ed = modeEditor(draft, true);
                    draftHolder.innerHTML = '';
                    draftHolder.appendChild(ed.el);
                    nameInput.value = '';
                    ed.focus();
                } }, 'Ajouter ce mode manuel');
                return h('div', { class: 'ss-new-mode' },
                    h('h4', {}, 'Ajouter un mode manuel'),
                    h('p', { class: 'ss-muted' }, 'Le nouveau mode s’ouvre à la fin de la liste ci-dessus ; il n’est créé qu’après « Créer le mode ».'),
                    h('div', { class: 'ss-bar' }, h('div', { class: 'ss-field-row' }, h('label', { for: 'ss-new-mode-name' }, 'Nom du nouveau mode'), nameInput), btn));
            }

            function slug(name) {
                var s = name.normalize ? name.normalize('NFD').replace(/[̀-ͯ]/g, '') : name;
                s = s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40);
                return s.length >= 2 ? s : 'mode';
            }

            // ----- Simulateur -----
            function drawSimulator() {
                simBox.innerHTML = '';
                var zoneOptions = (data.zones && data.zones.length ? data.zones : data.all_zones);
                var f = {
                    weight: h('input', { type: 'number', id: 'ss-sim-w', min: '1', step: '1', value: '340', required: true }),
                    thickness: h('input', { type: 'number', id: 'ss-sim-t', min: '0', step: '1', value: '29', required: true }),
                    subtotal: h('input', { type: 'number', id: 'ss-sim-s', min: '0', step: 'any', value: '12.95', required: true }),
                    zone: h('select', { id: 'ss-sim-z' }, zoneOptions.map(function (z) { return h('option', { value: z }, z); }))
                };
                var out = h('div', { 'aria-live': 'polite' });
                var hasResult = false;
                simRefresh = function () { if (hasResult) { run(); } };
                function run() {
                    out.textContent = 'Calcul…';
                    api('POST', '/carriers/simulate', { weight: f.weight.value, thickness: f.thickness.value, subtotal: f.subtotal.value, zone: f.zone.value })
                        .then(function (res) { hasResult = true; drawResult(res.data); })
                        .catch(function (e) { out.innerHTML = ''; self.fail(e); });
                }
                var form = h('form', { onsubmit: function (ev) { ev.preventDefault(); run(); } },
                    h('div', { class: 'ss-bar' },
                        h('div', { class: 'ss-field-row' }, h('label', { for: 'ss-sim-w' }, 'Poids du colis, emballage compris (g)'), f.weight),
                        h('div', { class: 'ss-field-row' }, h('label', { for: 'ss-sim-t' }, 'Épaisseur (mm)'), f.thickness),
                        h('div', { class: 'ss-field-row' }, h('label', { for: 'ss-sim-s' }, 'Montant des livres (€ TTC)'), f.subtotal),
                        h('div', { class: 'ss-field-row' }, h('label', { for: 'ss-sim-z' }, 'Zone'), f.zone),
                        h('button', { class: 'ss-btn ss-primary', type: 'submit' }, 'Calculer')));
                simBox.appendChild(form);
                simBox.appendChild(out);

                function drawResult(res) {
                    var ht = (data.settings || {}).prices_entered === 'ht';
                    out.innerHTML = '';
                    out.appendChild(h('div', { class: 'ss-table-scroll', role: 'region', tabindex: '0', 'aria-label': 'Résultat de la simulation' },
                        h('table', {},
                            h('caption', { class: 'ss-sr' }, 'Prix de chaque mode pour ce colis'),
                            h('thead', {}, h('tr', {}, ['Mode', 'Proposé au client', 'Grille (' + (ht ? 'HT' : 'TTC') + ')', 'Avec marge', 'Avant plancher (TTC)', 'Plancher légal', 'Prix TTC', 'Remarque'].map(function (t) { return h('th', { scope: 'col' }, t); }))),
                            h('tbody', {}, res.rows.map(function (r) {
                                var q = r.quote;
                                return h('tr', {},
                                    h('th', { scope: 'row' }, r.name),
                                    h('td', {}, r.offered ? 'Oui' : 'Non'),
                                    h('td', { class: 'ss-num' }, q ? money(q.grid_price) : '—'),
                                    h('td', { class: 'ss-num' }, q ? money(q.after_margin) : '—'),
                                    h('td', { class: 'ss-num' }, q ? money(q.price_before_floor) : '—'),
                                    h('td', { class: 'ss-num' }, q ? (q.floor > 0 ? money(q.floor) : 'aucun') : '—'),
                                    h('td', { class: 'ss-num' }, q ? h('strong', {}, money(q.price)) : '—'),
                                    h('td', {}, r.reason || (q && q.free_applied ? 'Offert dès le seuil du mode.' : (q && q.price > q.price_before_floor ? 'Relevé au plancher légal.' : (r.pickup ? 'Le client choisit un point de retrait.' : '')))));
                            })))));
                }
            }

            // ----- YAML avancé -----
            function drawYaml() {
                yamlBox.innerHTML = '';
                var area = h('textarea', { class: 'ss-yaml', spellcheck: 'false', id: 'ss-yaml-area' });
                area.value = data.yaml;
                var saveBtn = h('button', { class: 'ss-btn ss-primary', type: 'button', onclick: function () {
                    if (!window.confirm('Remplacer toute la configuration des modes par ce texte ?')) { return; }
                    saveBtn.disabled = true;
                    api('PUT', '/carriers', { yaml: area.value })
                        .then(function () { return reload(); })
                        .then(function () {
                            yamlStatus.textContent = 'Configuration enregistrée (l’ancienne version est conservée en carriers.yaml.bak).';
                            yamlStatus.focus();
                        })
                        .catch(function (e) { self.fail(e); })
                        .then(function () { saveBtn.disabled = false; });
                } }, 'Enregistrer le YAML');
                yamlBox.appendChild(h('p', { class: 'ss-muted' }, 'Réservé aux cas particuliers : l’écran ci-dessus suffit normalement. Le texte est vérifié avant enregistrement.'));
                yamlBox.appendChild(h('label', { for: 'ss-yaml-area', class: 'ss-sr' }, 'Configuration YAML des transporteurs'));
                yamlBox.appendChild(area);
                yamlBox.appendChild(h('div', { class: 'ss-bar', style: 'margin-top:.8rem' }, saveBtn));
                var yamlStatus = h('p', { class: 'ss-done', role: 'status', tabindex: '-1' });
                yamlBox.appendChild(yamlStatus);
            }

            reload();
        }

        // ---------------- Adresses bannies ----------------

        showBans() {
            var self = this;
            var listBox = h('div');
            var input = h('input', { type: 'email', placeholder: 'adresse@exemple.fr', required: true, id: 'ss-ban-email' });

            function draw(emails) {
                listBox.innerHTML = '';
                if (!emails.length) {
                    listBox.appendChild(h('p', { class: 'ss-muted' }, 'Aucune adresse bannie.'));
                    return;
                }
                listBox.appendChild(h('table', {}, h('tbody', {}, emails.map(function (email) {
                    return h('tr', {}, h('td', {}, email), h('td', { class: 'ss-num' }, h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                        if (!window.confirm('Débannir ' + email + ' ?')) { return; }
                        api('DELETE', '/bans/' + encodeURIComponent(email))
                            .then(function (res) { self.notify(email + ' n\'est plus bannie.', false); draw(res.data); })
                            .catch(function (e) { self.fail(e); });
                    } }, 'Débannir')));
                }))));
            }

            this.body.appendChild(h('p', { class: 'ss-muted' },
                'Une adresse bannie ne peut plus recevoir de code de connexion ni passer commande. '
                + 'Bannir efface aussi son profil enregistré (nom, adresse, téléphone) ; la désinscription newsletter est conservée.'));
            this.body.appendChild(h('form', { class: 'ss-bar', onsubmit: function (e) {
                e.preventDefault();
                var email = input.value.trim();
                if (!window.confirm('Bannir ' + email + ' ? Son profil enregistré sera effacé.')) { return; }
                api('POST', '/bans', { email: email })
                    .then(function (res) { input.value = ''; self.notify(email + ' est bannie.', false); draw(res.data); })
                    .catch(function (err) { self.fail(err); });
            } }, h('label', { class: 'ss-field', for: 'ss-ban-email' }, 'Adresse à bannir', input),
                h('button', { class: 'ss-btn ss-primary', type: 'submit' }, 'Bannir')));
            this.body.appendChild(listBox);

            api('GET', '/bans').then(function (res) { draw(res.data); }).catch(function (e) { self.fail(e); });
        }
    }

    customElements.define(TAG, SimpleShopPage);
})();
