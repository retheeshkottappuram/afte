Set WshShell = CreateObject("WScript.Shell")
Set objArgs = WScript.Arguments
strMode = "live"
If objArgs.Count > 0 Then
    strMode = objArgs(0)
End If
strScriptDir = CreateObject("Scripting.FileSystemObject").GetParentFolderName(WScript.ScriptFullName)
strCmd = "cmd /c """ & strScriptDir & "\start-trading-daemon-bg.bat " & strMode & """"
WshShell.Run strCmd, 0, False
