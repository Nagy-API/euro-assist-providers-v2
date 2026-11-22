// Livewire 4 bundles and starts Alpine itself; do not import or start Alpine here.
// Small, dependency-free helpers registered once Alpine is available.
document.addEventListener('alpine:init', () => {
    /**
     * Accessible modal dialog behaviour: moves focus into the dialog, keeps Tab inside it,
     * and returns focus to the element that opened it when it is removed.
     */
    window.Alpine.data('dialog', () => ({
        opener: null,
        init() {
            this.opener = document.activeElement;
            this.$nextTick(() => {
                const target = this.$root.querySelector('[data-autofocus]') ?? this.focusables()[0] ?? this.$root;
                target.focus();
            });
        },
        focusables() {
            return [...this.$root.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )].filter((el) => el.offsetParent !== null);
        },
        trap(event) {
            const items = this.focusables();
            if (items.length === 0) {
                event.preventDefault();
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },
        destroy() {
            if (this.opener && document.contains(this.opener)) {
                this.opener.focus();
            }
        },
    }));
});
