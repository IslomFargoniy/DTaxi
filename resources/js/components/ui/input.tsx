import * as React from "react"

import { cn } from "@/lib/utils"

function Input({ className, type, onKeyDown, onWheel, ...props }: React.ComponentProps<"input">) {
  const number = type === "number"
  return (
    <input
      type={type}
      // Son maydoni: "e", "+" (va manfiy bo'lmasa "-") yozilmaydi, g'ildirak qiymatni bilintirmay o'zgartirmaydi
      onKeyDown={(e) => {
        if (number && ["e", "E", "+", ...(Number(props.min) >= 0 ? ["-"] : [])].includes(e.key)) {
          e.preventDefault()
        }
        onKeyDown?.(e)
      }}
      onWheel={(e) => {
        if (number && document.activeElement === e.currentTarget) {
          e.currentTarget.blur()
        }
        onWheel?.(e)
      }}
      data-slot="input"
      className={cn(
        "border-input file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm",
        "focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]",
        "aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
        className
      )}
      {...props}
    />
  )
}

export { Input }
