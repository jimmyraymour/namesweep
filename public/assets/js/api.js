/**
 * api.js — tiny shared fetch helper for the server-rendered pages.
 * No framework. Submits the same form data the page would, but with
 * X-Requested-With so the server returns just the results fragment,
 * which replaces the placeholder section.
 */
export function postFragment(url, form) {
    const body = new URLSearchParams(new FormData(form));
    return fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body,
    });
}