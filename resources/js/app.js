// Theme toggling and state management
window.toggleTheme = function() {
    const isDark = document.documentElement.classList.contains('dark');
    if (isDark) {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('theme', 'light');
        localStorage.setItem('color-theme', 'light');
    } else {
        document.documentElement.classList.add('dark');
        localStorage.setItem('theme', 'dark');
        localStorage.setItem('color-theme', 'dark');
    }
    window.dispatchEvent(new CustomEvent('theme-changed', { detail: { dark: !isDark } }));
};

// Clipboard helper with toast feedback
window.copyToClipboard = function(text, message = 'Copied to clipboard!') {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            window.dispatchEvent(new CustomEvent('notify', { detail: { type: 'success', message } }));
        }).catch(() => {
            fallbackCopyText(text, message);
        });
    } else {
        fallbackCopyText(text, message);
    }
};

function fallbackCopyText(text, message) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
        document.execCommand('copy');
        window.dispatchEvent(new CustomEvent('notify', { detail: { type: 'success', message } }));
    } catch (err) {
        console.error('Failed to copy', err);
    }
    textArea.remove();
}

// Mini-games while waiting (Pure JS / Alpine)
import { memoryGame } from './games/memory';
import { snakeGame } from './games/snake';
import { typingGame } from './games/typing';
import { reactionGame } from './games/reaction';
import { gameHost } from './games/game-host';

document.addEventListener('alpine:init', () => {
    if (window.Alpine) {
        window.Alpine.data('gameHost', gameHost);
        window.Alpine.data('memoryGame', memoryGame);
        window.Alpine.data('snakeGame', snakeGame);
        window.Alpine.data('typingGame', typingGame);
        window.Alpine.data('reactionGame', reactionGame);
    }
});
