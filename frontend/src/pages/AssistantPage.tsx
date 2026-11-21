import { useEffect, useRef, useState, type FormEvent } from 'react';
import client from '../api/client';
import type {
  AssistantConversation,
  AssistantMessage,
  AssistantResult,
  AssistantStatus,
  AssistantTable,
} from '../api/types';
import { AssistantIcon, PlusIcon, SendIcon } from '../components/icons';

/**
 * H1 — AI-Powered Barangay Assistant (SRS §3.2.3, Figure 30.0; SDD §4.22).
 * Every answer separates "From BRIS records" (figures and tables straight
 * from the database) from "AI-generated recommendation" (decision support for
 * the Barangay Captain's review).
 */

const SUGGESTIONS = [
  'How many residents are registered in our barangay?',
  'Give me all recorded 4Ps beneficiaries.',
  'Which purok has the highest unemployment rate?',
  'Find residents with carpentry skills.',
  'What is the educational attainment distribution?',
  'What livelihood or training programs do our records suggest?',
];

export default function AssistantPage() {
  const [conversation, setConversation] = useState<AssistantConversation | null>(null);
  const [status, setStatus] = useState<AssistantStatus | null>(null);
  const [input, setInput] = useState('');
  const [sending, setSending] = useState(false);
  const [pendingQuestion, setPendingQuestion] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const bottomRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    client.get('/assistant/conversations/current').then(({ data }) => setConversation(data.data)).catch(() => setError('Could not load the assistant.'));
    client.get('/assistant/status').then(({ data }) => setStatus(data.data)).catch(() => undefined);
  }, []);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
  }, [conversation?.messages.length, pendingQuestion]);

  const ask = async (question: string) => {
    const text = question.trim();
    if (!text || !conversation || sending) return;
    setSending(true);
    setError(null);
    setPendingQuestion(text);
    setInput('');
    try {
      const { data } = await client.post('/assistant/messages', { conversation_id: conversation.id, message: text }, { timeout: 400_000 });
      const reply: AssistantMessage = data.data;
      const userMessage: AssistantMessage = { id: -reply.id, sender: 'user', content: text, records: null, created_at: reply.created_at };
      setConversation((c) => (c ? { ...c, messages: [...c.messages, userMessage, reply] } : c));
    } catch (err: unknown) {
      const status = (err as { response?: { status?: number } }).response?.status;
      setError(status === 429 ? 'Too many questions in a short time. Please wait a moment.' : 'The assistant is temporarily unavailable. Other BRIS functions are not affected.');
      setInput(text);
    } finally {
      setSending(false);
      setPendingQuestion(null);
    }
  };

  const newConversation = async () => {
    const { data } = await client.post('/assistant/conversations');
    setConversation(data.data);
    setError(null);
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    ask(input);
  };

  const messages = conversation?.messages ?? [];

  return (
    <div className="flex h-[calc(100vh-8rem)] min-h-[32rem] flex-col">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="page-title">AI-Powered Barangay Assistant</h2>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Ask questions about BRIS records in plain language. Answers come only from the barangay's records.
          </p>
        </div>
        <div className="flex items-center gap-3">
          {status && (
            <span
              className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium ${
                status.available ? 'bg-success/10 text-success-strong dark:text-success' : 'bg-error/10 text-error-strong dark:text-error'
              }`}
              title={`Local model: ${status.model}`}
            >
              <span className={`h-2 w-2 rounded-full ${status.available ? 'bg-success' : 'bg-error'}`} />
              {status.available ? 'Local AI model ready' : 'AI model offline'}
            </span>
          )}
          <button
            type="button"
            onClick={newConversation}
            disabled={sending}
            className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-white/10 dark:bg-charcoal dark:text-gray-200 dark:hover:bg-white/5"
          >
            <PlusIcon width={16} height={16} /> New conversation
          </button>
        </div>
      </div>

      <div className="mt-4 flex-1 overflow-y-auto rounded-2xl border border-gray-200 bg-bg-light p-4 dark:border-white/10 dark:bg-charcoal">
        {messages.length === 0 && !pendingQuestion && (
          <div className="mx-auto max-w-2xl py-8 text-center">
            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary-dark dark:text-primary">
              <AssistantIcon width={24} height={24} />
            </div>
            <p className="mt-3 text-sm text-gray-600 dark:text-gray-300">Try one of these questions:</p>
            <div className="mt-4 grid gap-2 sm:grid-cols-2">
              {SUGGESTIONS.map((s) => (
                <button
                  key={s}
                  type="button"
                  onClick={() => ask(s)}
                  className="rounded-lg border border-gray-200 bg-white px-3 py-2 text-left text-sm text-gray-700 hover:border-primary/50 hover:bg-primary/5 dark:border-white/10 dark:bg-white/5 dark:text-gray-200"
                >
                  {s}
                </button>
              ))}
            </div>
          </div>
        )}

        <div className="space-y-4">
          {messages.map((m) => (m.sender === 'user' ? <UserBubble key={m.id} text={m.content} /> : <AssistantBubble key={m.id} message={m} />))}
          {pendingQuestion && (
            <>
              <UserBubble text={pendingQuestion} />
              <div className="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400" role="status">
                <span className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-primary border-t-transparent" />
                Checking BRIS records… (the first question may take up to a minute while the local model loads)
              </div>
            </>
          )}
        </div>
        <div ref={bottomRef} />
      </div>

      {error && <div className="mt-3 rounded-lg border border-error/30 bg-error/10 px-4 py-2 text-sm text-error-strong dark:text-error">{error}</div>}

      <form onSubmit={handleSubmit} className="mt-3 flex gap-2">
        <input
          value={input}
          onChange={(e) => setInput(e.target.value)}
          maxLength={1000}
          placeholder="Ask about residents, 4Ps, assistance, employment, skills, education…"
          aria-label="Your question"
          disabled={sending || !conversation}
          className="flex-1 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30 dark:border-white/10 dark:bg-charcoal dark:text-gray-100"
        />
        <button
          type="submit"
          disabled={sending || !input.trim()}
          className="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-charcoal hover:bg-primary-dark hover:text-white disabled:opacity-50"
        >
          <SendIcon width={16} height={16} /> Ask
        </button>
      </form>
      <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
        Your questions are recorded in the assistant audit log. Conversations are deleted after the retention period approved by the barangay.
      </p>
    </div>
  );
}

function UserBubble({ text }: { text: string }) {
  return (
    <div className="flex justify-end">
      <div className="max-w-[80%] rounded-2xl rounded-br-sm bg-primary px-4 py-2 text-sm text-charcoal">{text}</div>
    </div>
  );
}

function AssistantBubble({ message }: { message: AssistantMessage }) {
  const records = message.records;
  const isAnswer = records?.kind === 'answer';
  const recommendations = records?.recommendations ?? [];

  return (
    <div className="flex justify-start">
      <div className="w-full max-w-[95%] space-y-3 rounded-2xl rounded-bl-sm border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800 dark:border-white/10 dark:bg-white/5 dark:text-gray-100">
        {isAnswer ? (
          <>
            <section>
              <SectionLabel tone="records">From BRIS records</SectionLabel>
              <p className="mt-1.5">{message.content}</p>
              {records?.summary_source === 'ai' && (
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Worded by the AI from the figures below; every number was checked against the records.</p>
              )}
              {(records?.results ?? []).map((r, i) => (
                <ResultView key={i} result={r} />
              ))}
            </section>

            {recommendations.length > 0 && (
              <section className="rounded-xl border border-amber-300/60 bg-amber-50 p-3 dark:border-amber-400/30 dark:bg-amber-400/10">
                <SectionLabel tone="ai">Recommendations chosen by the AI — for review by the Barangay Captain</SectionLabel>
                <ol className="mt-2 list-decimal space-y-2 pl-5">
                  {recommendations.map((rec, i) => (
                    <li key={i}>
                      <span>{rec.text}</span>
                      <span className="mt-0.5 block text-xs text-gray-600 dark:text-gray-300">Based on: {rec.based_on.join('; ')}</span>
                    </li>
                  ))}
                </ol>
                <p className="mt-2 text-xs text-gray-600 dark:text-gray-300">
                  Each option comes from a fixed rule applied to the BRIS figures shown; the AI picks the ones that fit your question. Decision support only. The assistant does not approve or reject beneficiaries or applicants.
                </p>
              </section>
            )}
          </>
        ) : (
          <p className={records?.kind === 'refused' || records?.kind === 'unavailable' ? 'text-error-strong dark:text-error' : ''}>{message.content}</p>
        )}
      </div>
    </div>
  );
}

function SectionLabel({ tone, children }: { tone: 'records' | 'ai'; children: string }) {
  return (
    <span
      className={`inline-block rounded-md px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide ${
        tone === 'records' ? 'bg-primary/15 text-primary-dark dark:text-primary' : 'bg-amber-200/70 text-amber-900 dark:bg-amber-400/20 dark:text-amber-200'
      }`}
    >
      {children}
    </span>
  );
}

function ResultView({ result }: { result: AssistantResult }) {
  return (
    <div className="mt-2 space-y-2">
      {result.figures.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {result.figures.map((f) => (
            <div key={f.label} className="rounded-lg bg-gray-100 px-3 py-1.5 dark:bg-white/10">
              <div className="text-[11px] text-gray-500 dark:text-gray-400">{f.label}</div>
              <div className="font-semibold">{f.value}</div>
            </div>
          ))}
        </div>
      )}
      {result.missing && <p className="rounded-lg bg-gray-100 px-3 py-2 text-gray-700 dark:bg-white/10 dark:text-gray-200">⚠ {result.missing}</p>}
      {result.tables.map((t) => (
        <ResultTable key={t.title} table={t} />
      ))}
      {result.notes.map((n) => (
        <p key={n} className="text-xs text-gray-500 dark:text-gray-400">
          Note: {n}
        </p>
      ))}
    </div>
  );
}

function ResultTable({ table }: { table: AssistantTable }) {
  if (table.rows.length === 0) return null;
  return (
    <details className="group rounded-lg border border-gray-200 dark:border-white/10" open={table.rows.length <= 15}>
      <summary className="cursor-pointer select-none px-3 py-2 text-xs font-medium text-gray-600 dark:text-gray-300">
        {table.title} — {table.total} row{table.total === 1 ? '' : 's'}
        {table.truncated ? ` (showing the first ${table.rows.length})` : ''}
      </summary>
      <div className="max-h-80 overflow-auto">
        <table className="w-full border-collapse text-left text-xs">
          <thead className="sticky top-0 bg-gray-50 dark:bg-charcoal">
            <tr>
              {table.columns.map((c) => (
                <th key={c} className="px-3 py-1.5 font-semibold">
                  {c}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {table.rows.map((row, i) => (
              <tr key={i} className="border-t border-gray-100 dark:border-white/5">
                {row.map((cell, j) => (
                  <td key={j} className="px-3 py-1.5">
                    {cell ?? '—'}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </details>
  );
}
