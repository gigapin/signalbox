/** Signal authoritative access loss from removable page hooks to their enclosing private shell. */
import { createContext } from 'react';

/** A denial removes private resources regardless of unsaved drafts or pending navigation decisions. */
export const TeamAccessContext = createContext<() => void>(() => undefined);
