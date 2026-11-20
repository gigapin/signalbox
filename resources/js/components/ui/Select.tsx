/** Keep native select values and form events while presenting a consistent list below the field. */
import {
    useCallback,
    useEffect,
    useId,
    useRef,
    useState,
    type ComponentPropsWithRef,
    type KeyboardEvent,
} from 'react';
import { createPortal } from 'react-dom';

/** Native select attributes and children remain the public contract. */
type SelectProps = ComponentPropsWithRef<'select'>;

/** Safe option projection read from the rendered native options when opening. */
interface Choice {
    /** Native submission value. */
    value: string;
    /** Text label, never interpreted as markup. */
    label: string;
    /** Includes disabled parent optgroups. */
    disabled: boolean;
}

/** Popup geometry and keyboard state captured outside rendering. */
interface Picker {
    /** Current native options. */
    choices: Choice[];
    /** Option with visual keyboard focus; DOM focus stays on the select. */
    active: number;
    /** Value selected when the picker opened. */
    selected: string;
    /** Accessible name from the control's labels. */
    label: string;
    /** Viewport geometry in CSS pixels, positioned strictly below the field. */
    top: number;
    left: number;
    width: number;
    height: number;
    /** Existing document CSP nonce, absent only in non-CSP test fixtures. */
    nonce: string | undefined;
    /** Keep custom options inside the active native modal's top layer, otherwise use the document body. */
    portalTarget: HTMLElement;
}

/**
 * @param props Native control attributes, controlled value and original change handler.
 * @returns A native combobox and a below-field portal; multiple/listbox selects retain their native picker.
 */
export default function Select({
    children,
    ref: forwardedRef,
    onChange,
    onKeyDown,
    onPointerDown,
    onMouseDown,
    onClick,
    onBlur,
    ...props
}: SelectProps) {
    const control = useRef<HTMLSelectElement>(null);
    /** Keep the internal picker reference and the caller's native ref synchronized. */
    const setControl = useCallback(
        (element: HTMLSelectElement | null) => {
            control.current = element;
            if (typeof forwardedRef === 'function') return forwardedRef(element);
            if (forwardedRef) forwardedRef.current = element;
        },
        [forwardedRef],
    );
    const popup = useRef<HTMLDivElement>(null);
    const [picker, setPicker] = useState<Picker | null>(null);
    const generated = useId().replace(/[^a-z0-9_-]/gi, '');
    const popupId = `select-picker-${generated}`;
    const search = useRef({ text: '', at: 0 });
    const isOpen = picker !== null;
    const activeIndex = picker?.active;
    const custom = !props.multiple && (props.size === undefined || props.size === 1);

    /** Read fresh options and layout; scroll the field into view only when insufficient room remains below. */
    function open(): Picker | null {
        const element = control.current;
        if (!element || element.matches(':disabled') || !custom) return null;
        let bounds = element.getBoundingClientRect();
        if (window.innerHeight - bounds.bottom < 120 && bounds.top > 120) {
            element.scrollIntoView?.({ block: 'center', inline: 'nearest' });
            bounds = element.getBoundingClientRect();
        }
        const choices = Array.from(element.options, (option): Choice => ({
            value: option.value,
            label: option.label,
            disabled:
                option.disabled ||
                (option.parentElement instanceof HTMLOptGroupElement &&
                    option.parentElement.disabled),
        }));
        const selected = choices.findIndex(
            (choice) => choice.value === element.value && !choice.disabled,
        );
        const modal = element.closest('dialog[open]');
        const state: Picker = {
            portalTarget: modal instanceof HTMLDialogElement ? modal : document.body,
            choices,
            active: selected >= 0 ? selected : choices.findIndex((choice) => !choice.disabled),
            selected: element.value,
            label:
                props['aria-label'] ??
                (Array.from(element.labels ?? [], (label) => label.textContent?.trim() ?? '').join(
                    ' ',
                ) ||
                    'Options'),
            top: bounds.bottom + 6,
            left: Math.max(8, Math.min(bounds.left, window.innerWidth - bounds.width - 8)),
            width: Math.min(bounds.width, Math.max(0, window.innerWidth - 16)),
            height: Math.max(32, Math.min(256, window.innerHeight - bounds.bottom - 14)),
            nonce: document.querySelector<HTMLElement>('script[nonce], style[nonce]')?.nonce,
        };
        search.current = { text: '', at: 0 };
        setPicker(state);
        return state;
    }

    /**
     * @param value Requested option value, validated again against the current control and disabled state.
     * Emit one normal native change event so existing controlled forms keep their validation and side effects.
     */
    function choose(value: string): void {
        const element = control.current;
        setPicker(null);
        if (!element || element.matches(':disabled')) return;
        const option = Array.from(element.options).find((entry) => entry.value === value);
        if (
            !option ||
            option.disabled ||
            (option.parentElement instanceof HTMLOptGroupElement && option.parentElement.disabled)
        )
            return;
        if (element.value !== value) {
            element.value = value;
            element.dispatchEvent(new Event('change', { bubbles: true }));
        }
        element.focus();
    }

    /** Close for outside interaction or changed anchor geometry, never for scrolling the options themselves. */
    useEffect(() => {
        if (!isOpen) return;
        const outside = (event: PointerEvent): void => {
            if (
                event.target instanceof Node &&
                !control.current?.contains(event.target) &&
                !popup.current?.contains(event.target)
            )
                setPicker(null);
        };
        const resize = (): void => setPicker(null);
        const scroll = (event: Event): void => {
            if (!(event.target instanceof Node) || !popup.current?.contains(event.target))
                setPicker(null);
        };
        document.addEventListener('pointerdown', outside);
        window.addEventListener('resize', resize);
        document.addEventListener('scroll', scroll, true);
        return () => {
            document.removeEventListener('pointerdown', outside);
            window.removeEventListener('resize', resize);
            document.removeEventListener('scroll', scroll, true);
        };
    }, [isOpen]);

    /** Keep the active option visible inside long lists without transferring DOM focus. */
    useEffect(() => {
        if (activeIndex !== undefined)
            document
                .getElementById(`${popupId}-${activeIndex}`)
                ?.scrollIntoView?.({ block: 'nearest' });
    }, [activeIndex, popupId]);

    /**
     * @param event Keyboard interaction on the native control.
     * Arrows/typeahead explore options; Enter/Space/Tab commit, while Escape cancels.
     */
    function keyboard(event: KeyboardEvent<HTMLSelectElement>): void {
        onKeyDown?.(event);
        if (event.defaultPrevented || !custom || event.ctrlKey || event.metaKey) return;
        if (event.key === 'Escape') {
            if (picker) event.preventDefault();
            setPicker(null);
            return;
        }
        if (event.key === 'Tab') {
            const choice = picker?.choices[picker.active];
            if (choice && !choice.disabled) choose(choice.value);
            setPicker(null);
            return;
        }
        const commit = event.key === 'Enter' || event.key === ' ';
        const moving = ['ArrowDown', 'ArrowUp', 'Home', 'End', 'PageUp', 'PageDown'].includes(
            event.key,
        );
        const typing = event.key.length === 1 && !event.altKey;
        if (!commit && !moving && !typing) return;
        event.preventDefault();
        if (commit && picker) {
            const choice = picker.choices[picker.active];
            if (choice && !choice.disabled) choose(choice.value);
            return;
        }
        const state = picker ?? open();
        if (!state) return;
        const enabled = state.choices
            .map((choice, index) => (choice.disabled ? -1 : index))
            .filter((index) => index >= 0);
        if (!enabled.length) return;
        let active = state.active;
        const current = Math.max(0, enabled.indexOf(active));
        if (event.key === 'Home') active = enabled[0] ?? active;
        else if (event.key === 'End') active = enabled.at(-1) ?? active;
        else if (moving && picker) {
            const direction = event.key === 'ArrowUp' || event.key === 'PageUp' ? -1 : 1;
            const jump = event.key.startsWith('Page') ? 10 : 1;
            active =
                enabled[Math.max(0, Math.min(enabled.length - 1, current + direction * jump))] ??
                active;
        } else if (typing && !commit) {
            const now = Date.now();
            const text =
                now - search.current.at < 700
                    ? search.current.text + event.key.toLowerCase()
                    : event.key.toLowerCase();
            search.current = { text, at: now };
            const prefix = text.split('').every((character) => character === text[0])
                ? (text[0] ?? '')
                : text;
            const candidates = [...enabled.slice(current + 1), ...enabled.slice(0, current + 1)];
            active =
                candidates.find((index) =>
                    state.choices[index]?.label.toLowerCase().startsWith(prefix),
                ) ?? active;
        }
        setPicker({ ...state, active });
    }

    return (
        <>
            <select
                {...props}
                ref={setControl}
                aria-expanded={custom ? picker !== null : undefined}
                aria-controls={picker ? popupId : props['aria-controls']}
                aria-activedescendant={
                    picker && picker.active >= 0 ? `${popupId}-${picker.active}` : undefined
                }
                onPointerDown={(event) => {
                    onPointerDown?.(event);
                    if (event.defaultPrevented || !custom || event.button !== 0) return;
                    event.preventDefault();
                    event.currentTarget.focus();
                    if (picker) setPicker(null);
                    else open();
                }}
                onMouseDown={(event) => {
                    onMouseDown?.(event);
                    if (custom && event.button === 0) event.preventDefault();
                }}
                onClick={(event) => {
                    onClick?.(event);
                    if (event.defaultPrevented || !custom) return;
                    event.preventDefault();
                    // Assistive activation may issue a click without pointer events.
                    if (event.detail === 0 && !picker) open();
                }}
                onKeyDown={keyboard}
                onBlur={(event) => {
                    onBlur?.(event);
                    setPicker(null);
                }}
                onChange={(event) => {
                    setPicker(null);
                    onChange?.(event);
                }}
            >
                {children}
            </select>
            {picker &&
                createPortal(
                    <>
                        <style
                            nonce={picker.nonce}
                        >{`#${popupId}{top:${picker.top}px;left:${picker.left}px;width:${picker.width}px;max-height:${picker.height}px}`}</style>
                        <div
                            ref={popup}
                            id={popupId}
                            role="listbox"
                            aria-label={picker.label}
                            className="ui-select-picker"
                        >
                            {picker.choices.map((choice, index) => (
                                <div
                                    id={`${popupId}-${index}`}
                                    key={`${index}:${choice.value}`}
                                    role="option"
                                    aria-selected={choice.value === picker.selected}
                                    aria-disabled={choice.disabled || undefined}
                                    data-active={index === picker.active}
                                    className="ui-select-option"
                                    onPointerDown={(event) => event.preventDefault()}
                                    onClick={() => {
                                        if (!choice.disabled) choose(choice.value);
                                    }}
                                >
                                    <span>{choice.label}</span>
                                    {choice.value === picker.selected && (
                                        <span aria-hidden="true">✓</span>
                                    )}
                                </div>
                            ))}
                        </div>
                    </>,
                    picker.portalTarget,
                )}
        </>
    );
}
