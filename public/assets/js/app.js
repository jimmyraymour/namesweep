/**
 * app.js — progressive enhancement for the Home check page.
 * Plain POST rendering remains the fallback when JS is off.
 */
import { postFragment } from './api.js';

const form = document.getElementById('check-form');
if (form) {
    form.addEventListener('submit', async (event) => {
        const names = form.querySelector('#names');
        if (names && !names.value.trim()) {
            return;
        }

        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = 'Checking...';
        event.preventDefault();

        try {
            const res = await postFragment(form.action || '/', form);
            const html = await res.text();
            document.querySelector('#report')?.remove();
            const holder = document.createElement('section');
            holder.id = 'report';
            holder.innerHTML = html;
            form.closest('.panel')?.after(holder);
        } catch {
            btn.textContent = 'Check';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Check';
        }
    });
}

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-export]');
    if (!button) {
        return;
    }
    const table = button.closest('.report')?.querySelector('[data-report-json]');
    if (!table) {
        return;
    }
    let data;
    try {
        data = JSON.parse(table.dataset.reportJson);
    } catch {
        return;
    }

    const type = button.dataset.export;
    if (type === 'json') {
        download('namesweep-report.json', JSON.stringify(data, null, 2), 'application/json');
        return;
    }
    if (type === 'csv') {
        const rows = [['name', 'module', 'tld', 'platform', 'status', 'requested', 'within_budget', 'registration_price', 'sale_price', 'currency', 'source', 'detail']];
        for (const [name, report] of Object.entries(data)) {
            for (const [module, resultRows] of Object.entries(report.results || {})) {
                for (const row of resultRows) {
                    rows.push([
                        name,
                        module,
                        row.tld || '',
                        row.platform || '',
                        row.status || '',
                        row.requested === false ? 'false' : 'true',
                        row.within_budget ?? '',
                        row.pricing?.registration ?? '',
                        row.sale_price ?? '',
                        row.pricing?.currency || '',
                        row.source || '',
                        row.detail || ''
                    ]);
                }
            }
        }
        download('namesweep-report.csv', rows.map(csvLine).join('\n'), 'text/csv');
    }
});

function csvLine(values) {
    return values.map((value) => {
        const text = String(value ?? '');
        return /[",\n]/.test(text) ? '"' + text.replaceAll('"', '""') + '"' : text;
    }).join(',');
}

function download(filename, content, type) {
    const blob = new Blob([content], { type });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}
