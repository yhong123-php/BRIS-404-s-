export interface StaffUser {
  id: number;
  full_name: string;
  username: string;
  email: string;
  role: string;
  position: string | null;
  must_change_password: boolean;
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
  civil_status: string;
  citizenship: string | null;
  occupation: string | null;
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

export interface PaginatedResponse<T> {
  data: T[];
  meta?: {
    current_page: number;
    last_page: number;
    total: number;
  };
  links?: Record<string, unknown>;
}
