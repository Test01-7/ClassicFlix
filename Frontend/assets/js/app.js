'use strict';

document.querySelectorAll('[data-password]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.password);
        if (!input || input.disabled) return;
        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(reveal));
        button.setAttribute('aria-label', reveal ? 'Ocultar contraseña' : 'Mostrar contraseña');
    });
});

const menuToggle = document.querySelector('.menu-toggle');
const navigation = document.getElementById('main-navigation');
if (menuToggle && navigation) {
    menuToggle.addEventListener('click', () => {
        const open = menuToggle.getAttribute('aria-expanded') !== 'true';
        menuToggle.setAttribute('aria-expanded', String(open));
        menuToggle.setAttribute('aria-label', open ? 'Cerrar navegación' : 'Abrir navegación');
        navigation.classList.toggle('is-open', open);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menuToggle.getAttribute('aria-expanded') === 'true') {
            menuToggle.setAttribute('aria-expanded', 'false');
            menuToggle.setAttribute('aria-label', 'Abrir navegación');
            navigation.classList.remove('is-open');
            menuToggle.focus();
        }
    });
}

document.querySelectorAll('img[data-fallback]').forEach((image) => {
    image.addEventListener('error', () => {
        if (image.dataset.fallback) {
            image.src = image.dataset.fallback;
            delete image.dataset.fallback;
        }
    }, { once: true });
});

const confirmation = document.getElementById('password_confirmation');
const adminMenu = document.querySelector('.admin-mobile-menu');
if (adminMenu) {
    adminMenu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && adminMenu.open) {
            adminMenu.open = false;
            adminMenu.querySelector('summary').focus();
        }
    });
}

const password = document.getElementById('password');
if (confirmation && password) {
    const check = () => confirmation.setCustomValidity(confirmation.value && confirmation.value !== password.value ? 'Las contraseñas deben coincidir.' : '');
    confirmation.addEventListener('input', check);
    password.addEventListener('input', check);
}
