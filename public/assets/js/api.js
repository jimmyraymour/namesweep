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

/**
 * Swap a returned fragment into the page, replacing the previous one.
 *
 * A success fragment already carries its own #id wrapper (#results,
 * #report); a 422 error fragment comes back bare, so it gets wrapped here.
 * On the first run there is a placeholder with that id to replace; if the
 * page ever renders without one, the fragment goes right after the form.
 */
export function renderFragment(form, html, id) {
    const holder = document.createElement('div');
    holder.innerHTML = html;

    let node = holder;
    if (holder.children.length === 1 && holder.firstElementChild.id === id) {
        node = holder.firstElementChild;
    } else {
        holder.id = id;
    }

    const current = document.getElementById(id);
    if (current) {
        current.replaceWith(node);
    } else {
        form.after(node);
    }
    return node;
}
