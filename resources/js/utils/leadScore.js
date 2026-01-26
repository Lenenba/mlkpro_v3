const SOURCE_SCORES = {
  web_form: 20,
  portal: 18,
  qr: 18,
  api: 18,
  referral: 15,
  phone: 12,
  email: 10,
  whatsapp: 12,
  sms: 8,
  import: 8,
  ads: 12,
  manual: 6,
  other: 5,
  unknown: 3,
};

const URGENCY_SCORES = {
  urgent: 20,
  high: 16,
  medium: 10,
  low: 5,
};

const normalizeNumber = (value) => {
  if (value === null || value === undefined || value === '') {
    return null;
  }
  if (typeof value === 'number') {
    return Number.isFinite(value) ? value : null;
  }
  const cleaned = String(value).replace(/[^0-9.]/g, '');
  if (!cleaned) {
    return null;
  }
  const parsed = Number(cleaned);
  return Number.isFinite(parsed) ? parsed : null;
};

const resolveBudget = (lead) => {
  if (!lead) {
    return null;
  }
  const meta = lead.meta || {};
  return (
    normalizeNumber(meta.budget) ||
    normalizeNumber(meta.estimated_budget) ||
    normalizeNumber(meta.budget_min) ||
    null
  );
};

const resolveUrgencyKey = (urgency) => {
  if (!urgency) {
    return null;
  }
  const value = String(urgency).toLowerCase();
  if (value.includes('urgent')) {
    return 'urgent';
  }
  if (value.includes('high')) {
    return 'high';
  }
  if (value.includes('medium')) {
    return 'medium';
  }
  if (value.includes('low')) {
    return 'low';
  }
  return null;
};

const resolveSourceKey = (channel) => {
  if (!channel) {
    return 'unknown';
  }
  const value = String(channel).toLowerCase();
  const aliases = {
    web: 'web_form',
    website: 'web_form',
    form: 'web_form',
  };
  const normalized = aliases[value] || value;
  const known = [
    'web_form',
    'portal',
    'qr',
    'api',
    'referral',
    'phone',
    'email',
    'whatsapp',
    'sms',
    'import',
    'ads',
    'manual',
    'other',
  ];
  if (known.includes(normalized)) {
    return normalized;
  }
  return 'other';
};

export const buildLeadScore = (lead, t) => {
  const badges = [];
  let score = 0;

  const sourceKey = resolveSourceKey(lead?.channel);
  score += SOURCE_SCORES[sourceKey] ?? 0;
  badges.push({
    key: 'source',
    tone: 'sky',
    label: t ? t(`requests.sources.${sourceKey}`) : sourceKey,
  });

  const urgencyKey = resolveUrgencyKey(lead?.urgency);
  if (urgencyKey) {
    score += URGENCY_SCORES[urgencyKey] ?? 0;
    badges.push({
      key: 'urgency',
      tone: urgencyKey === 'urgent' || urgencyKey === 'high' ? 'rose' : 'amber',
      label: t ? t(`requests.urgency.${urgencyKey}`) : urgencyKey,
    });
  }

  if (lead?.is_serviceable === true) {
    score += 25;
    badges.push({
      key: 'serviceable',
      tone: 'emerald',
      label: t ? t('requests.badges.serviceable') : 'Serviceable',
    });
  } else if (lead?.is_serviceable === false) {
    score -= 10;
    badges.push({
      key: 'serviceable',
      tone: 'rose',
      label: t ? t('requests.badges.not_serviceable') : 'Not serviceable',
    });
  }

  const budget = resolveBudget(lead);
  if (budget !== null) {
    if (budget >= 5000) {
      score += 20;
    } else if (budget >= 1000) {
      score += 15;
    } else if (budget >= 500) {
      score += 10;
    } else {
      score += 5;
    }
    const budgetLabel = t
      ? t('requests.badges.budget_amount', { amount: Math.round(budget) })
      : `Budget ${budget}`;
    badges.push({
      key: 'budget',
      tone: 'indigo',
      label: budgetLabel,
    });
  }

  if (lead?.contact_email) {
    score += 5;
  }
  if (lead?.contact_phone) {
    score += 5;
  }
  if (lead?.contact_name) {
    score += 3;
  }
  if (lead?.customer_id) {
    score += 5;
  }

  score = Math.max(0, Math.min(100, Math.round(score)));

  return { score, badges };
};

export const badgeClass = (tone) => {
  switch (tone) {
    case 'emerald':
      return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300';
    case 'rose':
      return 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300';
    case 'indigo':
      return 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300';
    case 'amber':
      return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
    case 'sky':
      return 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300';
    default:
      return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
  }
};
