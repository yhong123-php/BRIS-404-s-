import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';

/**
 * In-app dialogs that replace the browser's prompt(), confirm(), and alert():
 * labelled fields with validation, confirmations (optionally "type X to
 * confirm" for irreversible actions), and notices with a copy button.
 *
 *   const dialog = useDialog();
 *   const values = await dialog.form({ title, fields: [...] }); // null if cancelled
 *   if (await dialog.confirm({ title, message })) { ... }
 *   await dialog.notice({ title, message, copyText });
 */

export interface DialogField {
  name: string;
  label: string;
  type?: 'text' | 'textarea' | 'select' | 'date' | 'number';
  options?: { value: string; label: string }[];
  required?: boolean;
  defaultValue?: string;
  placeholder?: string;
  help?: string;
  max?: string;
  min?: string;
}

interface FormOptions { title: string; description?: ReactNode; fields: DialogField[]; submitLabel?: string; tone?: 'primary' | 'danger' }
interface ConfirmOptions { title: string; message: ReactNode; confirmLabel?: string; tone?: 'primary' | 'danger'; typeToConfirm?: string }
interface NoticeOptions { title: string; message: ReactNode; copyText?: string; tone?: 'info' | 'warning' }

type Pending = { id: number } & (
  | { kind: 'form'; opts: FormOptions; resolve: (v: Record<string, string> | null) => void }
  | { kind: 'confirm'; opts: ConfirmOptions; resolve: (v: boolean) => void }
  | { kind: 'notice'; opts: NoticeOptions; resolve: () => void }
);

interface DialogApi {
  form: (opts: FormOptions) => Promise<Record<string, string> | null>;
  confirm: (opts: ConfirmOptions) => Promise<boolean>;
  notice: (opts: NoticeOptions) => Promise<void>;
}

const DialogContext = createContext<DialogApi | undefined>(undefined);

export function DialogProvider({ children }: { children: ReactNode }) {
  const [pending, setPending] = useState<Pending | null>(null);
  const counter = useRef(0);

  const api = useMemo<DialogApi>(() => ({
    form: (opts) => new Promise((resolve) => setPending({ id: ++counter.current, kind: 'form', opts, resolve })),
    confirm: (opts) => new Promise((resolve) => setPending({ id: ++counter.current, kind: 'confirm', opts, resolve })),
    notice: (opts) => new Promise((resolve) => setPending({ id: ++counter.current, kind: 'notice', opts, resolve })),
  }), []);

  const close = () => setPending(null);

  return (
    <DialogContext.Provider value={api}>
      {children}
      {pending && <DialogView key={pending.id} pending={pending} onClose={close} />}
    </DialogContext.Provider>
  );
}

function DialogView({ pending, onClose }: { pending: Pending; onClose: () => void }) {
  const first = useRef<HTMLElement | null>(null);
  const [values, setValues] = useState<Record<string, string>>(() =>
    pending.kind === 'form' ? Object.fromEntries(pending.opts.fields.map((f) => [f.name, f.defaultValue ?? ''])) : {},
  );
  const [typed, setTyped] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [copied, setCopied] = useState(false);

  const cancel = useCallback(() => {
    if (pending.kind === 'form') pending.resolve(null);
    else if (pending.kind === 'confirm') pending.resolve(false);
    else pending.resolve();
    onClose();
  }, [pending, onClose]);

  useEffect(() => {
    first.current?.focus();
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') cancel(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [cancel]);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (pending.kind === 'form') {
      const errs: Record<string, string> = {};
      for (const f of pending.opts.fields) {
        if (f.required && !(values[f.name] ?? '').trim()) errs[f.name] = `${f.label} is required.`;
      }
      setErrors(errs);
      if (Object.keys(errs).length) return;
      pending.resolve(Object.fromEntries(Object.entries(values).map(([k, v]) => [k, v.trim()])));
    } else if (pending.kind === 'confirm') {
      if (pending.opts.typeToConfirm && typed !== pending.opts.typeToConfirm) return;
      pending.resolve(true);
    } else {
      pending.resolve();
    }
    onClose();
  };

  const tone = pending.kind === 'notice' ? 'primary' : pending.opts.tone ?? 'primary';
  const blocked = pending.kind === 'confirm' && !!pending.opts.typeToConfirm && typed !== pending.opts.typeToConfirm;

  return (
    <div className="dialog-backdrop" onMouseDown={(e) => e.target === e.currentTarget && cancel()}>
      <form className="dialog" role="dialog" aria-modal="true" aria-labelledby="dialog-title" onSubmit={submit}>
        <h3 id="dialog-title" className="dialog-title">{pending.opts.title}</h3>

        {pending.kind === 'form' && (
          <>
            {pending.opts.description && <p className="dialog-text">{pending.opts.description}</p>}
            {pending.opts.fields.map((f, i) => {
              const common = {
                id: `dlg-${f.name}`,
                value: values[f.name] ?? '',
                onChange: (e: { target: { value: string } }) => setValues((v) => ({ ...v, [f.name]: e.target.value })),
                'aria-invalid': !!errors[f.name],
                ref: i === 0 ? (el: HTMLElement | null) => { first.current = el; } : undefined,
              };
              return (
                <div key={f.name} className="dialog-field">
                  <label htmlFor={common.id}>{f.label}{f.required ? ' *' : ''}</label>
                  {f.type === 'textarea' ? (
                    <textarea {...common} rows={3} placeholder={f.placeholder} />
                  ) : f.type === 'select' ? (
                    <select {...common}>
                      {!f.required || !f.defaultValue ? <option value="">Select…</option> : null}
                      {(f.options ?? []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                  ) : (
                    <input {...common} type={f.type ?? 'text'} placeholder={f.placeholder} max={f.max} min={f.min} />
                  )}
                  {f.help && <span className="dialog-help">{f.help}</span>}
                  {errors[f.name] && <span className="dialog-error">{errors[f.name]}</span>}
                </div>
              );
            })}
          </>
        )}

        {pending.kind === 'confirm' && (
          <>
            <div className="dialog-text">{pending.opts.message}</div>
            {pending.opts.typeToConfirm && (
              <div className="dialog-field">
                <label htmlFor="dlg-confirm">Type <strong>{pending.opts.typeToConfirm}</strong> to continue</label>
                <input id="dlg-confirm" value={typed} onChange={(e) => setTyped(e.target.value)} ref={(el) => { first.current = el; }} autoComplete="off" />
              </div>
            )}
          </>
        )}

        {pending.kind === 'notice' && (
          <>
            <div className="dialog-text">{pending.opts.message}</div>
            {pending.opts.copyText && (
              <div className="dialog-copy">
                <code>{pending.opts.copyText}</code>
                <button type="button" onClick={() => { navigator.clipboard?.writeText(pending.opts.copyText ?? ''); setCopied(true); }}>{copied ? 'Copied' : 'Copy'}</button>
              </div>
            )}
          </>
        )}

        <div className="dialog-actions">
          {pending.kind !== 'notice' && <button type="button" onClick={cancel}>Cancel</button>}
          <button
            type="submit"
            className={tone === 'danger' ? 'button-danger' : 'button-primary'}
            disabled={blocked}
            ref={pending.kind === 'notice' || (pending.kind === 'confirm' && !pending.opts.typeToConfirm) ? (el) => { first.current = el; } : undefined}
          >
            {pending.kind === 'form' ? pending.opts.submitLabel ?? 'Save' : pending.kind === 'confirm' ? pending.opts.confirmLabel ?? 'Confirm' : 'OK'}
          </button>
        </div>
      </form>
    </div>
  );
}

// eslint-disable-next-line react-refresh/only-export-components
export function useDialog(): DialogApi {
  const ctx = useContext(DialogContext);
  if (!ctx) throw new Error('useDialog must be used within DialogProvider');
  return ctx;
}
