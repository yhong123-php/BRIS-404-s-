import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import client from '../api/client';
import PasswordInput from '../components/PasswordInput';
import { useAuth } from '../context/AuthContext';

export default function ChangePasswordPage() {
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const { refreshUser, user } = useAuth();
  // Forced on first login; otherwise opened from the profile menu and can be left.
  const forced = user?.must_change_password !== false;
  const navigate = useNavigate();

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await client.post('/admin/change-password', {
        current_password: currentPassword,
        new_password: newPassword,
        new_password_confirmation: confirm,
      });
      await refreshUser();
      navigate('/');
    } catch (err: any) {
      setError(err?.response?.data?.message ?? 'Could not change password.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="auth-page">
      <form className="auth-card" onSubmit={handleSubmit}>
        <h1>Change Password</h1>
        <p className="auth-subtitle">
          {forced
            ? 'Your account was created with a temporary password. Set a new one to continue.'
            : 'Use at least 8 characters. You will stay signed in.'}
        </p>
        {error && <div className="form-error">{error}</div>}
        <label>
          Current password
          <PasswordInput value={currentPassword} onChange={setCurrentPassword} required />
        </label>
        <label>
          New password
          <PasswordInput value={newPassword} onChange={setNewPassword} required minLength={8} />
        </label>
        <label>
          Confirm new password
          <PasswordInput value={confirm} onChange={setConfirm} required minLength={8} />
        </label>
        <button type="submit" disabled={submitting}>
          {submitting ? 'Saving…' : forced ? 'Save and continue' : 'Save new password'}
        </button>
        {!forced && <Link to="/" className="note" style={{ textAlign: 'center' }}>Cancel</Link>}
      </form>
    </div>
  );
}
