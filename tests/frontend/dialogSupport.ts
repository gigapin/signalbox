/** Supply only the native dialog methods absent from jsdom, with exact descriptor restoration. */

/**
 * @returns Cleanup restoring both prototype descriptors after the rendered dialogs are unmounted.
 */
export function installDialogSupport(): () => void {
    const show = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'showModal');
    const close = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'close');
    Object.defineProperty(HTMLDialogElement.prototype, 'showModal', {
        configurable: true,
        value: function (this: HTMLDialogElement): void {
            this.setAttribute('open', '');
        },
    });
    Object.defineProperty(HTMLDialogElement.prototype, 'close', {
        configurable: true,
        value: function (this: HTMLDialogElement): void {
            this.removeAttribute('open');
        },
    });

    return () => {
        if (show) Object.defineProperty(HTMLDialogElement.prototype, 'showModal', show);
        else Reflect.deleteProperty(HTMLDialogElement.prototype, 'showModal');
        if (close) Object.defineProperty(HTMLDialogElement.prototype, 'close', close);
        else Reflect.deleteProperty(HTMLDialogElement.prototype, 'close');
    };
}
