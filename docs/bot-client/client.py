"""
کلاینت پایتون برای Reseller API پنل — برای ربات‌های aiogram/python-telegram-bot.

    api = ShahPanel("https://your-domain.example/api/v1")
    api.login("reseller1", "secret")
    for acc in api.accounts(status="active")["items"]:
        print(acc["username"])
"""

from __future__ import annotations

from typing import Any

import requests


class ShahPanelError(RuntimeError):
    def __init__(self, code: str, message: str, status: int, errors: dict | None = None):
        super().__init__(message)
        self.code = code
        self.status = status
        self.errors = errors or {}

    @property
    def needs_reauth(self) -> bool:
        """توکن باطل یا منقضی شده — باید دوباره login کرد."""
        return self.status == 401

    @property
    def rate_limited(self) -> bool:
        return self.status == 429


class ShahPanel:
    def __init__(self, base_url: str, token: str | None = None, timeout: int = 60):
        self.base_url = base_url.rstrip("/")
        self.token = token
        self.timeout = timeout
        self._session = requests.Session()

    # ── auth ────────────────────────────────────────────────────────────
    def login(self, username: str, password: str, two_fa_code: str | None = None,
              device_name: str = "telegram-bot") -> dict[str, Any]:
        payload = {"username": username, "password": password, "device_name": device_name}
        if two_fa_code:
            payload["two_fa_code"] = two_fa_code
        data = self._request("POST", "/auth/login", json=payload)
        self.token = data["token"]
        return data

    def me(self) -> dict[str, Any]:
        return self._request("GET", "/auth/me")

    def logout(self) -> dict[str, Any]:
        return self._request("POST", "/auth/logout")

    # ── catalog ─────────────────────────────────────────────────────────
    def packages(self, seller_id: int | None = None) -> list[dict[str, Any]]:
        params = {"seller_id": seller_id} if seller_id else {}
        return self._request("GET", "/catalog/packages", params=params)

    def servers(self, package_id: int | None = None) -> list[dict[str, Any]]:
        params = {"package_id": package_id} if package_id else {}
        return self._request("GET", "/catalog/servers", params=params)

    # ── accounts ────────────────────────────────────────────────────────
    def accounts(self, **filters: Any) -> dict[str, Any]:
        return self._request("GET", "/accounts", params=filters)

    def account(self, key: str | int) -> dict[str, Any]:
        return self._request("GET", f"/accounts/{key}")

    def preview_price(self, package_id: int, duration_id: int,
                      data_gb: float | None = None) -> dict[str, Any]:
        params = {"package_id": package_id, "package_duration_id": duration_id}
        if data_gb is not None:
            params["data_gb"] = data_gb
        return self._request("GET", "/accounts/preview", params=params)

    def sell(self, package_id: int, duration_id: int, username: str,
             data_gb: float | None = None, **extra: Any) -> dict[str, Any]:
        payload = {
            "package_id": package_id,
            "package_duration_id": duration_id,
            "remote_username": username,
            "sanaei_client_name": username,
            **extra,
        }
        if data_gb is not None:
            payload["data_gb"] = data_gb
        return self._request("POST", "/accounts", json=payload)

    def config(self, key: str | int) -> dict[str, Any]:
        return self._request("GET", f"/accounts/{key}/config")

    def usage(self, key: str | int, refresh: bool = False) -> dict[str, Any]:
        return self._request("GET", f"/accounts/{key}/usage",
                             params={"refresh": 1} if refresh else {})

    def renew(self, key: str | int, mode: str = "same", duration_id: int | None = None,
              data_gb: float | None = None) -> dict[str, Any]:
        payload: dict[str, Any] = {"renewal_mode": mode}
        if duration_id is not None:
            payload["package_duration_id"] = duration_id
        if data_gb is not None:
            payload["data_gb"] = data_gb
        return self._request("POST", f"/accounts/{key}/renew", json=payload)

    def enable(self, key: str | int) -> dict[str, Any]:
        return self._request("POST", f"/accounts/{key}/enable")

    def disable(self, key: str | int) -> dict[str, Any]:
        return self._request("POST", f"/accounts/{key}/disable")

    # ── wallet / resellers / stats ──────────────────────────────────────
    def wallet(self) -> dict[str, Any]:
        return self._request("GET", "/wallet")

    def transactions(self, **filters: Any) -> dict[str, Any]:
        return self._request("GET", "/wallet/transactions", params=filters)

    def resellers(self, **filters: Any) -> dict[str, Any]:
        return self._request("GET", "/resellers", params=filters)

    def dashboard(self) -> dict[str, Any]:
        return self._request("GET", "/stats/dashboard")

    # ── core ────────────────────────────────────────────────────────────
    def _request(self, method: str, path: str, params: dict | None = None,
                 json: dict | None = None) -> Any:
        headers = {"Accept": "application/json"}
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"

        response = self._session.request(
            method, self.base_url + path,
            params=params or None, json=json,
            headers=headers, timeout=self.timeout,
        )

        try:
            body = response.json()
        except ValueError:
            raise ShahPanelError("bad_response",
                               f"پاسخ نامعتبر از سرور (HTTP {response.status_code})",
                               response.status_code) from None

        if not body.get("ok"):
            # خطاهای اعتبارسنجی لاراول کلید errors دارند
            if "errors" in body:
                first = next(iter(body["errors"].values()))
                message = first[0] if isinstance(first, list) else str(first)
                raise ShahPanelError("validation_failed", message,
                                   response.status_code, body["errors"])

            err = body.get("error", {})
            raise ShahPanelError(err.get("code", "unknown"),
                               err.get("message", "خطای ناشناخته"),
                               response.status_code)

        data = body.get("data")

        # صفحه‌بندی را کنار داده برگردان تا ربات صفحهٔ بعد را بتواند بگیرد
        pagination = body.get("meta", {}).get("pagination")
        if pagination is not None:
            return {"items": data, "pagination": pagination}

        return data
