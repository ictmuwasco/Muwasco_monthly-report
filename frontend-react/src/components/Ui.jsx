export function Spinner({ label = 'Loading…' }) {
  return (
    <div className="flex items-center justify-center py-12 text-gray-500">
      <svg className="mr-3 h-5 w-5 animate-spin text-blue-600" viewBox="0 0 24 24" fill="none">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
      </svg>
      {label}
    </div>
  );
}

export function EmptyState({ title = 'No records', message }) {
  return (
    <div className="py-10 text-center text-sm text-gray-500">
      <p className="font-medium text-gray-700">{title}</p>
      {message && <p className="mt-1">{message}</p>}
    </div>
  );
}

export function FieldErrors({ errors }) {
  if (!errors || Object.keys(errors).length === 0) return null;
  const rows = Object.entries(errors).flatMap(([k, msgs]) =>
    (Array.isArray(msgs) ? msgs : [msgs]).map((m) => ({ k, m })),
  );
  if (rows.length === 0) return null;

  // A single generic message (e.g. login failure) reads better without
  // the field-name prefix — show it as one clean sentence.
  const genericOnly = rows.length === 1 && (rows[0].k === '_server' || rows[0].k === 'username');

  return (
    <div className="mb-4 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">
      {genericOnly ? (
        <p>{rows[0].m}</p>
      ) : (
        <>
          <p className="font-semibold mb-1">Please fix the following:</p>
          <ul className="list-disc pl-5">
            {rows.map((r, i) => (
              <li key={i}>
                {r.k !== '_server' && (
                  <span className="font-medium capitalize">{r.k.replace(/_/g, ' ')}: </span>
                )}
                {r.m}
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  );
}

export const STATUS_STYLES = {
  draft: 'bg-gray-100 text-gray-700',
  open: 'bg-ocean-100 text-ocean-800',
  submitted: 'bg-ocean-200 text-ocean-900',
  under_review: 'bg-amber-100 text-amber-700',
  changes_requested: 'bg-orange-100 text-orange-700',
  approved: 'bg-aqua-100 text-aqua-800',
  rejected: 'bg-red-100 text-red-700',
  closed: 'bg-deep-100 text-deep-700',
};

export function StatusBadge({ status }) {
  return (
    <span className={`badge ${STATUS_STYLES[status] ?? 'bg-deep-100 text-deep-700'}`}>
      {String(status ?? '').replace(/_/g, ' ')}
    </span>
  );
}

export function Pagination({ meta, onPage }) {
  if (!meta || meta.last_page <= 1) return null;
  return (
    <div className="flex items-center justify-between border-t border-gray-200 px-4 py-3">
      <p className="text-sm text-gray-500">
        Page {meta.current_page} of {meta.last_page} ({meta.total} total)
      </p>
      <div className="flex gap-2">
        <button
          className="btn btn-secondary"
          disabled={meta.current_page <= 1}
          onClick={() => onPage(meta.current_page - 1)}
        >
          Previous
        </button>
        <button
          className="btn btn-secondary"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
        >
          Next
        </button>
      </div>
    </div>
  );
}