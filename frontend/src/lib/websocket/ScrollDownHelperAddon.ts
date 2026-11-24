import type { ITerminalAddon, Terminal } from '@xterm/xterm';

/**
 * Floating "scroll to bottom" button over the terminal, ported from
 * plugins/XtermScrollDownHelperAddon.ts: appears once output pushes the viewport up
 * and hides again whenever the user is back at the bottom.
 */
export class ScrollDownHelperAddon implements ITerminalAddon {
    private terminal: Terminal = undefined as unknown as Terminal;
    private element?: HTMLDivElement;

    activate(terminal: Terminal): void {
        this.terminal = terminal;

        this.terminal.onScroll(() => {
            if (this.isScrolledDown()) {
                this.hide();
            } else {
                this.show();
            }
        });

        this.terminal.onLineFeed(() => {
            if (!this.isScrolledDown()) {
                this.show();
            }
        });

        // The button is built up front but stays hidden: it is only useful when
        // there is output below the viewport, and the scroll handler above takes
        // it from there.
        this.create();
        this.hide();
    }

    dispose(): void {
        // ignore
    }

    show(): void {
        if (!this.terminal || !this.terminal.element) {
            return;
        }
        if (this.element) {
            this.element.style.visibility = 'visible';
            return;
        }

        this.create();
    }

    hide(): void {
        if (this.element) {
            this.element.style.visibility = 'hidden';
        }
    }

    isScrolledDown(): boolean {
        return this.terminal.buffer.active.viewportY === this.terminal.buffer.active.baseY;
    }

    /**
     * Builds the floating button and attaches it to the terminal.
     *
     * The arrow is inlined with its own size instead of being styled by an icon
     * font: the FontAwesome classes the original markup carried are gone with the
     * Preact frontend, and without them the SVG collapsed to nothing, leaving an
     * empty grey block in the corner of the console.
     */
    private create(): void {
        if (!this.terminal || !this.terminal.element || this.element) {
            return;
        }

        this.terminal.element.style.position = 'relative';

        this.element = document.createElement('div');
        this.element.setAttribute('role', 'button');
        this.element.setAttribute('aria-label', '回到底部');
        this.element.title = '回到底部';
        this.element.innerHTML =
            '<svg aria-hidden="true" focusable="false" width="1.4em" height="1.4em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>';
        this.element.style.position = 'absolute';
        this.element.style.right = '1.5rem';
        this.element.style.bottom = '.5rem';
        this.element.style.padding = '.5rem';
        this.element.style.fontSize = '1.25em';
        this.element.style.color = '#ffffff';
        this.element.style.borderRadius = '4px';
        this.element.style.boxShadow = '0 2px 8px #000';
        this.element.style.backgroundColor = '#252526';
        this.element.style.zIndex = '999';
        this.element.style.cursor = 'pointer';

        this.element.addEventListener('click', () => {
            this.terminal.scrollToBottom();
        });

        this.terminal.element.appendChild(this.element);
    }
}
