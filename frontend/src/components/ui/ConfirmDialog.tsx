import { useState, type ReactNode } from 'react'
import { Button } from './Button'
import { Modal } from './Modal'

interface ConfirmDialogProps {
  open: boolean
  title: string
  message: ReactNode
  confirmLabel?: string
  cancelLabel?: string
  tone?: 'danger' | 'primary'
  onConfirm: () => void | Promise<void>
  onCancel: () => void
}

/**
 * Guards every destructive action. `onConfirm` may be async — the dialog stays
 * open with the button in a loading state until it settles, so a failed delete
 * surfaces as a toast behind a dialog the user can retry from rather than
 * vanishing silently.
 */
export function ConfirmDialog({
  open,
  title,
  message,
  confirmLabel = 'Delete',
  cancelLabel = 'Cancel',
  tone = 'danger',
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  const [isWorking, setWorking] = useState(false)

  const confirm = async () => {
    setWorking(true)
    try {
      await onConfirm()
    } finally {
      setWorking(false)
    }
  }

  return (
    <Modal
      open={open}
      size="sm"
      title={title}
      onClose={isWorking ? () => {} : onCancel}
      footer={
        <>
          <Button variant="ghost" onClick={onCancel} disabled={isWorking}>
            {cancelLabel}
          </Button>
          <Button variant={tone} onClick={confirm} loading={isWorking}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <p className="confirm__message">{message}</p>
    </Modal>
  )
}
