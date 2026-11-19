export interface StaffUser {
  id: number;
  full_name: string;
  username: string;
  email: string;
  role: 'captain' | 'secretary';
  position: string | null;
  must_change_password: boolean;
  /** The role's permissions, sent by the backend (SDD Table 5.1). */
  permissions: string[];
}

export interface Purok {
  id: number;
  name: string;
  purok_number: string;
  description: string | null;
  residents_count?: number;
  households_count?: number;
}

export interface Resident {
  id: number;
  resident_number: string;
  last_name: string;
  first_name: string;
  middle_name: string | null;
  suffix: string | null;
  full_name: string;
  sex: 'male' | 'female';
  birth_date: string;
  age: number | null;
  birth_place: string | null;
  civil_status: string;
  religion: string | null;
  citizenship: string | null;
  occupation: string | null;
  employment_status: EmploymentStatus | null;
  educational_attainment: string | null;
  monthly_income_bracket: string | null;
  contact_number: string | null;
  email: string | null;
  purok_id: number | null;
  purok?: { id: number; name: string } | null;
  house_number: string | null;
  street: string | null;
  voter_status: string | null;
  residency_start_date: string | null;
  status: 'active' | 'deceased' | 'transferred_out' | 'archived';
  remarks: string | null;
}

export interface DuplicateCandidate {
  resident: { data: Resident };
  similarity_percent: number;
  same_birth_date: boolean;
}

export interface HouseholdMemberRow {
  id: number;
  resident_id: number;
  resident_name: string;
  relationship_to_head: string | null;
  is_head: boolean;
  date_joined: string;
}

export interface Household {
  id: number;
  household_number: string;
  purok_id: number;
  house_number: string | null;
  street: string | null;
  dwelling_ownership: string | null;
  construction_type: string | null;
  water_source: string | null;
  electricity_access: boolean;
  toilet_type: string | null;
  status: string;
  remarks: string | null;
  size?: number;
  members?: HouseholdMemberRow[];
}

export interface PurokStatistics {
  purok_id: number;
  total_residents: number;
  total_households: number;
  male_count: number;
  female_count: number;
  age_band_counts: Record<string, number>;
  senior_count: number;
  pwd_count: number;
  solo_parent_count: number;
}

export interface FamilyRelationshipRow {
  id: number;
  resident_id: number;
  resident_name: string;
  related_resident_id: number;
  related_resident_name: string;
  relationship_type: string;
  start_date: string | null;
  end_date: string | null;
}

export interface Family {
  id: number;
  family_name: string;
  family_head_id: number | null;
  family_head_name?: string | null;
  member_count?: number;
  remarks: string | null;
  relationships?: FamilyRelationshipRow[];
}

export interface AuditLogEntry {
  id: number;
  user: string;
  action: string;
  module: string;
  table_name: string;
  record_id: number | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  ip_address: string | null;
  client_type: string;
  created_at: string;
}

export interface DashboardActivity {
  action: string;
  module: string;
  table_name: string;
  record_id: number | null;
  user: string;
  created_at: string;
}

export interface DashboardAction { label: string; count: number; link: string }

export interface DashboardSummary {
  action_list?: DashboardAction[];
  events_this_month?: Record<string, number>;
  active_relief?: { id: number; title: string; allocated: number; claimed: number }[];
  residents_total: number;
  residents_by_sex: Record<string, number>;
  households_total: number;
  puroks_total: number;
  recent_activity?: DashboardActivity[];
  top_skill?: { name: string; count: number } | null;
  unemployed_count?: number;
}

export interface SkillsReport {
  by_type: { skill_type_id: number; name: string; count: number }[];
  total_skilled_residents: number;
}

export interface BeneficiaryRow {
  resident_id: number;
  resident_name: string;
  purok: string | null;
  classification_type: string;
  date_granted: string | null;
}

export interface BeneficiariesReport {
  by_type: { classification_type_id: number; name: string; count: number }[];
  selected_classification_type_id: number | null;
  beneficiaries: BeneficiaryRow[];
}

export interface EmploymentReport {
  employed_count: number;
  unemployed_count: number;
  unspecified_count: number;
  unemployed_residents: { resident_id: number; resident_name: string; purok: string | null; age: number | null }[];
}

export interface AchievementsReport {
  by_category: { category: string; count: number }[];
  total: number;
  recent: {
    id: number;
    resident_id: number;
    resident_name: string;
    title: string;
    category: string | null;
    date_achieved: string | null;
  }[];
}

export interface PaginatedResponse<T> {
  data: T[];
  meta?: {
    current_page: number;
    last_page: number;
    total: number;
  };
  links?: Record<string, unknown>;
}

export type EmploymentStatus = 'employed' | 'self_employed' | 'unemployed' | 'not_in_labor_force';

export const EMPLOYMENT_STATUS_LABELS: Record<EmploymentStatus, string> = {
  employed: 'Employed',
  self_employed: 'Self-employed',
  unemployed: 'Unemployed',
  not_in_labor_force: 'Not in labor force (student, retired, homemaker)',
};

/** Monthly household income brackets — must match Resident::INCOME_BRACKETS on the backend. */
export const INCOME_BRACKETS = ['No income', 'Below 5,000', '5,000–10,000', '10,000–20,000', '20,000–40,000', 'Above 40,000'];

/** Highest educational attainment — must match Resident::EDUCATION_LEVELS on the backend. */
export const EDUCATION_LEVELS: Record<string, string> = {
  no_formal_education: 'No formal education',
  elementary_level: 'Elementary level',
  elementary_graduate: 'Elementary graduate',
  high_school_level: 'High school level',
  high_school_graduate: 'High school graduate',
  vocational: 'Vocational / technical',
  college_level: 'College level',
  college_graduate: 'College graduate',
  post_graduate: 'Post-graduate',
};

export interface SocialAssistanceRecord {
  id: number;
  assistance_type: 'medical' | 'educational' | 'livelihood' | 'other';
  program_name: string;
  description_or_amount: string | null;
  date_received: string;
  source: string | null;
  recorded_by: string | null;
}

// ---- H1: AI-Powered Barangay Assistant ----

export interface AssistantTable {
  title: string;
  columns: string[];
  rows: (string | number | null)[][];
  total: number;
  truncated: boolean;
}

export interface AssistantResult {
  function: string;
  figures: { label: string; value: string | number }[];
  tables: AssistantTable[];
  notes: string[];
  missing: string | null;
}

export interface AssistantRecords {
  kind: 'answer' | 'help' | 'refused' | 'unavailable';
  summary_source?: 'ai' | 'system';
  results?: AssistantResult[];
  recommendations?: { text: string; based_on: string[] }[];
  calls?: { function: string; parameters: Record<string, unknown> }[];
}

export interface AssistantMessage {
  id: number;
  sender: 'user' | 'assistant';
  content: string;
  records: AssistantRecords | null;
  created_at: string;
}

export interface AssistantConversation {
  id: number;
  started_at: string;
  delete_after: string;
  messages: AssistantMessage[];
}

export interface AssistantStatus {
  available: boolean;
  model: string;
  categories: { key: string; label: string }[];
}

export interface AssistantQueryLog {
  id: number;
  created_at: string;
  user: string | null;
  question: string;
  query_category: string | null;
  function_called: string | null;
  parameters: Record<string, unknown> | null;
  personal_data_types: string | null;
  row_count: number;
  sent_to_model: 'none' | 'aggregated' | 'masked';
  outcome: 'answered' | 'refused' | 'unavailable' | 'no_data';
}
